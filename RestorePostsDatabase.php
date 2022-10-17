<?php
// YOT CLUB DATABASE RESTORATION SCRIPT
global $wpdb;

error_log("LOG: Database Archive Restoration Beginning", 0);

// init data
$tablePosts = $wpdb->prefix . "posts";
$tablePostMeta = $wpdb->prefix . "postmeta";
$tablePostsArchive = $wpdb->prefix . "posts_archive";
$tablePostMetaArchive = $wpdb->prefix . "postmeta_archive";
$expirydate = date('Y-m-d 00:00:00',strtotime("-200 days"));



// select shop_orders less than 200 days old from posts_archive table
// $sqlPosts = $wpdb->prepare( "SELECT * FROM {$tablePostsArchive} WHERE post_type = 'shop_order' AND post_date >= %s", $expirydate);
$sqlPosts = $wpdb->prepare( "SELECT * FROM {$tablePostsArchive} WHERE post_type = 'shop_order'");
$resultPosts = $wpdb->get_results( $sqlPosts );

if( count($resultPosts) > 0 ){
	foreach($resultPosts as $order){
		// begin transaction
		$wpdb->query('START TRANSACTION');

		// insert posts_archive data into posts table
		$dataPosts = json_decode(json_encode($order), true);
		$resultArchivePosts = $wpdb->insert($tablePosts, $dataPosts);
		
		// insert postmeta_archive data into postmeta table
		$resultArchivePostMeta = true;
		$sqlPostMeta = $wpdb->prepare( "SELECT * FROM {$tablePostMetaArchive} WHERE post_id = '%d'", $order->ID);
		$resultPostMeta = $wpdb->get_results( $sqlPostMeta );

		if(count($resultPostMeta) > 0){
			$sqlArchivePostMeta = "INSERT INTO {$tablePostMeta} (meta_id, post_id, meta_key, meta_value) VALUES";

			foreach($resultPostMeta as $meta){
				$escMetaKey = esc_sql($meta->meta_key);
				$escMetaValue = esc_sql($meta->meta_value);
				$sqlArchivePostMeta.= ("('{$meta->meta_id}', '{$meta->post_id}', '{$escMetaKey}', '{$escMetaValue}'),");
			}
			$sqlArchivePostMeta = rtrim($sqlArchivePostMeta, ",");
			
			$resultArchivePostMeta = $wpdb->query($sqlArchivePostMeta);
		}
		
		// delete shop order from post and postmeta archive tables
		$resultDeletePost = $wpdb->delete( $tablePostsArchive, array( 'ID' => $order->ID ) );
		$resultDeletePostMeta = $wpdb->delete( $tablePostMetaArchive, array( 'post_id' => $order->ID ) );
		
		// check results of all queries
		if($resultArchivePosts && $resultArchivePostMeta && $resultDeletePost && $resultDeletePostMeta) {
			// everything has worked, commit transaction
			$wpdb->query('COMMIT'); 
		} else {
			// error has occured, rollback
			error_log($wpdb->last_error, 0);
			$wpdb->query('ROLLBACK'); 
		}
	}
} else {
	if($wpdb->last_error) {
		error_log($wpdb->last_error, 0);
	}
}

// error_log("LOG: Database Archive Restoration Complete!", 0);