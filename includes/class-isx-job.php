<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * A migration job: an isolated working directory under storage/<id>/ that holds
 * the archive, the database dump, the file work-list and a JSON state cursor so
 * long operations can resume across many small AJAX requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISX_Job {

	// How long a job with no heartbeat at all is presumed abandoned. Well past
	// ISX_Admin::STALL_LIMIT on purpose: this only catches runs that died with
	// no request left to notice them, and must never reclaim a job that is
	// merely between slow ticks.
	const ABANDONED_GRACE = HOUR_IN_SECONDS;

	private $id;
	private $dir;
	private $state = array();

	private function __construct( $id ) {
		$this->id  = $id;
		$this->dir = ISX_STORAGE_PATH . '/' . $id;
	}

	/**
	 * Create a fresh job.
	 *
	 * @param string $type export|import
	 * @return ISX_Job|null null if the job directory/state file couldn't be
	 *                       written — logged with the actual cause instead of
	 *                       silently handing back a job that reads as
	 *                       "ไม่พบงาน" on the first poll.
	 */
	public static function create( $type ) {
		// Confirms this function was actually entered — under verbose logging
		// this is the one line that tells "AJAX reached PHP and got this far"
		// apart from anything below it, which matters when the real failure
		// turns out to be further upstream (guard(), WP still booting, ...)
		// and neither of the two branches below ever runs.
		ISX_Logger::log_debug( $type, 'เริ่มสร้างงานใหม่', array() );

		try {
			self::gc_done_jobs();

			$id  = 'isx_' . wp_generate_password( 20, false, false );
			$job = new self( $id );

			if ( ! wp_mkdir_p( $job->dir ) ) {
				ISX_Logger::log_error(
					$type,
					'สร้างโฟลเดอร์งานไม่สำเร็จ',
					array(
						'job'     => $id,
						'dir'     => $job->dir,
						'parent_writable' => is_writable( dirname( $job->dir ) ) ? 'yes' : 'no',
					)
				);
				return null;
			}

			$job->state = array(
				'id'       => $id,
				'type'     => $type,
				'step'     => 'init',
				'cursor'   => array(),
				'progress' => 0,
				'created'  => time(),
				// Per-job secret stored on disk. Import rewrites wp_users / wp_options,
				// which invalidates the admin session mid-run; this key lets the poller
				// keep authenticating against the on-disk job instead of the database.
				'secret'   => wp_generate_password( 32, false, false ),
			);

			if ( ! $job->save() ) {
				ISX_Logger::log_error(
					$type,
					'เขียนไฟล์ state ของงานไม่สำเร็จ',
					array(
						'job'      => $id,
						'dir'      => $job->dir,
						'writable' => is_writable( $job->dir ) ? 'yes' : 'no',
					)
				);
				return null;
			}

			return $job;
		} catch ( \Throwable $e ) {
			// Catches anything unforeseen — a DB call added to gc_done_jobs() down
			// the line hitting a dead connection, a corrupt state.json throwing
			// instead of decoding to null, etc. Without this, such a case would
			// either fatal into a blank page or bubble past ajax_export_start()
			// as an uncaught error — either way, no line in this log to point at.
			ISX_Logger::log_error(
				$type,
				'สร้างงานล้มเหลว: ข้อผิดพลาดที่ไม่คาดคิด',
				array(
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
					'file'      => $e->getFile() . ':' . $e->getLine(),
				)
			);
			return null;
		}
	}

	/**
	 * Load an existing job by id (validated to prevent path traversal).
	 *
	 * @param string $id
	 * @return ISX_Job|null
	 */
	public static function load( $id ) {
		if ( ! preg_match( '/^isx_[A-Za-z0-9]+$/', (string) $id ) ) {
			return null;
		}

		// Also look under the plugin's own storage/ dir, not just the currently
		// configured one: if the storage path changed (or fell back to the
		// default) while this job was in flight, the job is still perfectly
		// intact — just no longer where ISX_STORAGE_PATH now points. Reporting
		// "ไม่พบงาน" for that is what turned a settings hiccup into a dead run.
		$candidates = array( ISX_STORAGE_PATH, untrailingslashit( ISX_PATH . 'storage' ) );

		foreach ( array_unique( $candidates ) as $base ) {
			$dir  = untrailingslashit( $base ) . '/' . $id;
			$file = $dir . '/state.json';
			if ( ! is_file( $file ) ) {
				continue;
			}
			$state = json_decode( file_get_contents( $file ), true );
			if ( ! is_array( $state ) ) {
				continue;
			}
			$job        = new self( $id );
			$job->dir   = $dir;
			$job->state = $state;
			return $job;
		}

		return null;
	}

	/**
	 * Where load() looks for a job — quoted back in the "ไม่พบงาน" error so the
	 * log says which directories were actually searched instead of leaving it a
	 * guess.
	 *
	 * @return array
	 */
	public static function search_paths() {
		return array_values( array_unique( array( ISX_STORAGE_PATH, untrailingslashit( ISX_PATH . 'storage' ) ) ) );
	}

	/**
	 * Whether this job's directory is still on disk, regardless of whether its
	 * state can be read.
	 *
	 * load() returning null answers two very different questions with the same
	 * value: "this job is gone" and "this job's state could not be read just
	 * now". Anything about to take a destructive action on the strength of
	 * "the job is dead" — releasing a multipart upload, for instance — needs
	 * the first meaning specifically, and this is how to ask for it.
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function exists( $id ) {
		if ( ! preg_match( '/^isx_[A-Za-z0-9]+$/', (string) $id ) ) {
			return false;
		}
		foreach ( self::search_paths() as $base ) {
			if ( is_dir( untrailingslashit( $base ) . '/' . $id ) ) {
				return true;
			}
		}
		return false;
	}

	public function id() {
		return $this->id;
	}

	public function dir() {
		return $this->dir;
	}

	public function archive() {
		return $this->dir . '/archive.wpress';
	}

	public function db_dump() {
		return $this->dir . '/database.isxdb';
	}

	public function file_list() {
		return $this->dir . '/files.list';
	}

	/**
	 * Work list for the import's clean-then-restore pass: the existing
	 * wp-content files to delete, enumerated once and consumed with a cursor.
	 */
	public function clean_list() {
		return $this->dir . '/clean.list';
	}

	public function get( $key, $default = null ) {
		return isset( $this->state[ $key ] ) ? $this->state[ $key ] : $default;
	}

	public function set( $key, $value ) {
		$this->state[ $key ] = $value;
		return $this;
	}

	public function state() {
		return $this->state;
	}

	/**
	 * Persist the job's state.
	 *
	 * Written to a temp file and renamed into place, never straight over the
	 * live one. Several drivers read this file while a step is writing it — the
	 * browser poll, the loopback chain, WP-Cron, and the upload sweep that runs
	 * on every admin_init — and file_put_contents() is not atomic, so any of
	 * them could catch it half-written. json_decode() then failed, load()
	 * returned null, and callers read that as "this job does not exist":
	 * ajax_run() logged "ไม่พบงาน", and worse,
	 * ISX_Export::sweep_orphaned_uploads() concluded the owning job was dead and
	 * aborted a multipart upload that was still running — the next part came
	 * back "HTTP 404: The specified multipart upload does not exist".
	 *
	 * The upload step saves after every part, so a 6GB package meant well over
	 * a thousand of these windows in a single export. rename() closes all of it:
	 * a reader sees either the previous state or the new one, never a fragment.
	 *
	 * @return bool
	 */
	public function save() {
		$json = wp_json_encode( $this->state );
		$path = $this->dir . '/state.json';
		// Per-process temp name: two drivers saving at once must not share a
		// scratch file, or one's rename() publishes the other's half-written one.
		$tmp  = $path . '.' . getmypid() . '.tmp';

		$written = @file_put_contents( $tmp, $json );
		$ok      = ( $written === strlen( $json ) ) && @rename( $tmp, $path );
		if ( ! $ok ) {
			@unlink( $tmp );
		}

		// Nobody checks this return value at the call sites (a step has usually
		// already done its work by the time it saves, so there's nothing useful
		// to undo), which is exactly why it has to be loud here: a state.json
		// that didn't land means the job forgets its cursor and re-runs a step,
		// or forgets it finished — both of which look like a mystery hang rather
		// than the full disk they actually are.
		if ( ! $ok ) {
			ISX_Logger::log_error(
				(string) $this->get( 'type', 'system' ),
				'บันทึกสถานะงานไม่สำเร็จ — พื้นที่ดิสก์ของเซิร์ฟเวอร์อาจเต็ม',
				array(
					'job'        => $this->id,
					'step'       => (string) $this->get( 'step', '' ),
					'free_space' => @disk_free_space( $this->dir ),
				)
			);
		}

		return $ok;
	}

	/**
	 * Remove the whole job directory.
	 *
	 * @return void
	 */
	public function cleanup() {
		self::rrmdir( $this->dir );
	}

	/**
	 * Ask whoever is driving this job to stop at the next safe point.
	 *
	 * A marker file rather than a state.json field on purpose: cancelling has to
	 * work *while* a step is mid-run holding the lock, and that step will write
	 * its own in-memory state back over state.json when it finishes — silently
	 * undoing anything written there from the outside. A separate file survives
	 * that, and needs no lock of its own to set.
	 *
	 * @return bool
	 */
	public function request_cancel() {
		return false !== @file_put_contents( $this->dir . '/.cancel', (string) time() );
	}

	/**
	 * Whether someone has asked this job to stop.
	 *
	 * The clearstatcache() is what makes this work at all, not a precaution: PHP
	 * caches stat results — including "this file does not exist" — for the life
	 * of the request. The whole point of the flag is to be noticed by a step
	 * that started *before* it was written, by a different process, so without
	 * flushing the entry every caller inside that request would keep reading the
	 * cached "no" and the job would run to completion regardless.
	 *
	 * @return bool
	 */
	public function is_cancel_requested() {
		$path = $this->dir . '/.cancel';
		clearstatcache( true, $path );
		return is_file( $path );
	}

	/**
	 * Mark the job as finished and persist that to disk *before* trimming its
	 * scratch files, so a duplicate poll that arrives after this call — the
	 * browser tab and the WP-Cron driver can race the same job, see
	 * with_lock() — still finds a loadable job to report "done" for, instead
	 * of a deleted directory that reads as "ไม่พบงาน" even though the run
	 * actually succeeded. The (now tiny) state.json is swept up later by
	 * gc_done_jobs().
	 *
	 * @param string $message
	 * @param bool   $error Whether this finish represents a failed run — persisted
	 *                      as 'last_error' so a duplicate poll landing after this
	 *                      call (see above) still reports the correct outcome
	 *                      instead of reading as success.
	 * @return void
	 */
	public function finish( $message, $error = false ) {
		$this->set( 'step', 'done' );
		$this->set( 'last_message', $message );
		$this->set( 'last_progress', 100 );
		$this->set( 'last_error', (bool) $error );
		$this->set( 'finished', time() );
		$this->save();

		$items = @scandir( $this->dir );
		if ( $items === false ) {
			return;
		}
		foreach ( $items as $item ) {
			// .cancel stays for the same reason state.json does: a driver that was
			// already in flight when this ran still needs to see that the job was
			// cancelled rather than treat the leftovers as work to resume.
			if ( $item === '.' || $item === '..' || $item === 'state.json' || $item === '.lock' || $item === '.cancel' ) {
				continue;
			}
			$path = $this->dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
	}

	/**
	 * Sweep job directories left behind by finish() once they're old enough
	 * that no in-flight duplicate poll could still be racing them. Runs on
	 * every new job creation instead of its own cron hook, since that's
	 * naturally rate-limited to "about as often as someone runs an export or
	 * import".
	 *
	 * @return void
	 */
	private static function gc_done_jobs() {
		$grace = 5 * MINUTE_IN_SECONDS;

		// Sweep every directory load() would search, not just the currently
		// configured one — a job that ran before the storage path changed still
		// has a dir (and possibly a pending upload) under the old base.
		$dirs = array();
		foreach ( self::search_paths() as $base ) {
			$found = glob( untrailingslashit( $base ) . '/isx_*', GLOB_ONLYDIR );
			if ( $found ) {
				$dirs = array_merge( $dirs, $found );
			}
		}
		if ( ! $dirs ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			$id  = basename( $dir );
			$job = self::load( $id );
			if ( $job === null ) {
				continue;
			}

			if ( $job->get( 'step' ) === 'done' ) {
				if ( time() - (int) $job->get( 'finished', 0 ) < $grace ) {
					continue;
				}
				$job->cleanup();
				continue;
			}

			// Not done, and nothing has touched it for far longer than the
			// stall watchdog's window: the worker died without any request
			// left to notice (fatal, OOM, host kill), so the watchdog never
			// got to run. Release whatever it left pending on the bucket
			// before the state file — the only record of that upload — goes
			// away with the directory.
			$last = (int) $job->get( 'heartbeat', (int) $job->get( 'created', 0 ) );
			if ( $last === 0 || time() - $last < self::ABANDONED_GRACE ) {
				continue;
			}
			if ( $job->get( 'type', 'export' ) === 'export' ) {
				ISX_Export::abort_pending_upload( $job );
			}
			$job->cleanup();
		}
	}

	/**
	 * Run $fn while holding an exclusive OS-level lock on this job, so the
	 * browser's AJAX poll and the WP-Cron background driver never execute the
	 * same pipeline step concurrently (which could double-insert DB rows on
	 * import, or double-pack files on export).
	 *
	 * @param callable $fn
	 * @return mixed|false false if the lock is already held elsewhere.
	 */
	public function with_lock( callable $fn ) {
		$handle = fopen( $this->dir . '/.lock', 'c' );
		if ( $handle === false ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}

		// Re-read state from disk: the other driver may have advanced the job
		// since this object was loaded, and we're about to write it back.
		$fresh = self::load( $this->id );
		if ( $fresh !== null ) {
			$this->state = $fresh->state;
		}

		$result = $fn( $this );

		flock( $handle, LOCK_UN );
		fclose( $handle );
		return $result;
	}

	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
