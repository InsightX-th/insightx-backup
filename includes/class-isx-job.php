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
	 * @return ISX_Job
	 */
	public static function create( $type ) {
		self::gc_done_jobs();

		$id  = 'isx_' . wp_generate_password( 20, false, false );
		$job = new self( $id );
		wp_mkdir_p( $job->dir );
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
		$job->save();
		return $job;
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
		$job  = new self( $id );
		$file = $job->dir . '/state.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$state = json_decode( file_get_contents( $file ), true );
		if ( ! is_array( $state ) ) {
			return null;
		}
		$job->state = $state;
		return $job;
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

	public function save() {
		file_put_contents( $this->dir . '/state.json', wp_json_encode( $this->state ) );
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
	 * Mark the job as finished and persist that to disk *before* trimming its
	 * scratch files, so a duplicate poll that arrives after this call — the
	 * browser tab and the WP-Cron driver can race the same job, see
	 * with_lock() — still finds a loadable job to report "done" for, instead
	 * of a deleted directory that reads as "ไม่พบงาน" even though the run
	 * actually succeeded. The (now tiny) state.json is swept up later by
	 * gc_done_jobs().
	 *
	 * @param string $message
	 * @return void
	 */
	public function finish( $message ) {
		$this->set( 'step', 'done' );
		$this->set( 'last_message', $message );
		$this->set( 'last_progress', 100 );
		$this->set( 'finished', time() );
		$this->save();

		$items = @scandir( $this->dir );
		if ( $items === false ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' || $item === 'state.json' || $item === '.lock' ) {
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
		$dirs  = glob( ISX_STORAGE_PATH . '/isx_*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return;
		}
		foreach ( $dirs as $dir ) {
			$id  = basename( $dir );
			$job = self::load( $id );
			if ( $job === null ) {
				continue;
			}
			if ( $job->get( 'step' ) !== 'done' ) {
				continue;
			}
			if ( time() - (int) $job->get( 'finished', 0 ) < $grace ) {
				continue;
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
