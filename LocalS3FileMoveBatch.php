<?php

use FSs3Repo;

/**
 * Helper class for file movement
 * @ingroup FileRepo
 */
class LocalS3FileMoveBatch {
	var $file, $cur, $olds, $oldCount, $archive, $target, $db;

	function __construct( File $file, Title $target ) {
		$this->file = $file;
		$this->target = $target;
		$this->oldHash = $this->file->repo->getHashPath( $this->file->getName() );
		$this->newHash = $this->file->repo->getHashPath( $this->target->getDBkey() );
		$this->oldName = $this->file->getName();
		$this->newName = $this->file->repo->getNameFromTitle( $this->target );
		$this->oldRel = $this->oldHash . $this->oldName;
		$this->newRel = $this->newHash . $this->newName;
		$this->db = $file->repo->getMasterDb();
	}

	/**
	 * Add the current image to the batch
	 */
	function addCurrent() {
		$this->cur = array( $this->oldRel, $this->newRel );
	}

	/**
	 * Add the old versions of the image to the batch
	 */
	function addOlds() {
		$archiveBase = 'archive';
		$this->olds = array();
		$this->oldCount = 0;

		$result = $this->db->select( 'oldimage',
			array( 'oi_archive_name', 'oi_deleted' ),
			array( 'oi_name' => $this->oldName ),
			__METHOD__
		);
		while( $row = $this->db->fetchObject( $result ) ) {
			$oldName = $row->oi_archive_name;
			$bits = explode( '!', $oldName, 2 );
			if( count( $bits ) != 2 ) {
				wfDebug( "Invalid old file name: $oldName \n" );
				continue;
			}
			list( $timestamp, $filename ) = $bits;
			if( $this->oldName != $filename ) {
				wfDebug( "Invalid old file name: $oldName \n" );
				continue;
			}
			$this->oldCount++;
			// Do we want to add those to oldCount?
			if( $row->oi_deleted & File::DELETED_FILE ) {
				continue;
			}
			$this->olds[] = array(
				"{$archiveBase}/{$this->oldHash}{$oldName}",
				"{$archiveBase}/{$this->newHash}{$timestamp}!{$this->newName}"
			);
		}
		$this->db->freeResult( $result );
	}

	/**
	 * Perform the move.
	 */
	function execute() {
		$repo = $this->file->repo;
		$status = $repo->newGood();
		$triplets = $this->getMoveTriplets();

		$triplets = $this->removeNonexistentFiles( $triplets );
		$statusDb = $this->doDBUpdates();
		wfDebugLog( 'imagemove', "Renamed {$this->file->name} in database: {$statusDb->successCount} successes, {$statusDb->failCount} failures" );
		$statusMove = $repo->storeBatch( $triplets, FSs3Repo::DELETE_SOURCE );
		wfDebugLog( 'imagemove', "Moved files for {$this->file->name}: {$statusMove->successCount} successes, {$statusMove->failCount} failures" );
		if( !$statusMove->isOk() ) {
			wfDebugLog( 'imagemove', "Error in moving files: " . $statusMove->getWikiText() );
			$this->db->rollback();
		}

		$status->merge( $statusDb );
		$status->merge( $statusMove );
		return $status;
	}

	/**
	 * Do the database updates and return a new FileRepoStatus indicating how
	 * many rows where updated.
	 *
	 * @return FileRepoStatus
	 */
	function doDBUpdates() {
		$repo = $this->file->repo;
		$status = $repo->newGood();
		$dbw = $this->db;

		// Update current image
		$dbw->update(
			'image',
			array( 'img_name' => $this->newName ),
			array( 'img_name' => $this->oldName ),
			__METHOD__
		);
		if( $dbw->affectedRows() ) {
			$status->successCount++;
		} else {
			$status->failCount++;
		}

		// Update old images
		$dbw->update(
			'oldimage',
			array(
				'oi_name' => $this->newName,
				'oi_archive_name = ' . $dbw->strreplace( 'oi_archive_name', $dbw->addQuotes($this->oldName), $dbw->addQuotes($this->newName) ),
			),
			array( 'oi_name' => $this->oldName ),
			__METHOD__
		);
		$affected = $dbw->affectedRows();
		$total = $this->oldCount;
		$status->successCount += $affected;
		$status->failCount += $total - $affected;

		return $status;
	}

	/**
	 * Generate triplets for FSs3Repo::storeBatch().
	 */
	function getMoveTriplets() {
		$moves = array_merge( array( $this->cur ), $this->olds );
		$triplets = array(); // The format is: (srcUrl, destZone, destUrl)
		foreach( $moves as $move ) {
			// $move: (oldRelativePath, newRelativePath)
			$srcUrl = $this->file->repo->getVirtualUrl() . '/public/' . rawurlencode( $move[0] );
			$triplets[] = array( $srcUrl, 'public', $move[1] );
			wfDebugLog( 'imagemove', "Generated move triplet for {$this->file->name}: {$srcUrl} :: public :: {$move[1]}" );
		}
		return $triplets;
	}

	/**
	 * Removes non-existent files from move batch.
	 */
	function removeNonexistentFiles( $triplets ) {
		$files = array();
		foreach( $triplets as $file )
			$files[$file[0]] = $file[0];
		$result = $this->file->repo->fileExistsBatch( $files /*, FSs3Repo::FILES_ONLY*/ );
		$filteredTriplets = array();
		foreach( $triplets as $file )
			if( $result[$file[0]] ) {
				$filteredTriplets[] = $file;
			} else {
				wfDebugLog( 'imagemove', "File {$file[0]} does not exist" );
			}
		return $filteredTriplets;
	}
}
