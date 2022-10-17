<?php
// WORDPRESS DATABASE ARCHIVE SCRIPT
global $wpdb;

// error_log("LOG: Database Archive Beginning", 0);

// init data
$tablePosts = $wpdb->prefix . "posts";
$tablePostMeta = $wpdb->prefix . "postmeta";
$tableOrderItems = $wpdb->prefix . "woocommerce_order_items";
$tableOrderItemMeta = $wpdb->prefix . "woocommerce_order_itemmeta";

$tablePostsArchive = $wpdb->prefix . "posts_archive";
$tablePostMetaArchive = $wpdb->prefix . "postmeta_archive";
$tableOrderItemsArchive = $wpdb->prefix . "woocommerce_order_items_archive";
$tableOrderItemMetaArchive = $wpdb->prefix . "woocommerce_order_itemmeta_archive";

$expirydate = date('Y-m-d 00:00:00',strtotime("-200 days"));


// *** table creation only needs to be run once, code may not be needed ***/
// create archive tables if they do not already exist
$sqlCreatePostsArchive = "CREATE TABLE IF NOT EXISTS {$tablePostsArchive} LIKE {$tablePosts}";
$resultCreatePostsArchive = $wpdb->query( $sqlCreatePostsArchive );
if( !$resultCreatePostsArchive ){
    error_log($wpdb->last_error, 0);
}

$sqlCreatePostMetaArchive = "CREATE TABLE IF NOT EXISTS {$tablePostMetaArchive} LIKE {$tablePostMeta}";
$resultCreatePostMetaArchive = $wpdb->query( $sqlCreatePostMetaArchive );
if( !$resultCreatePostMetaArchive ){
    error_log($wpdb->last_error, 0);
}

$sqlCreateOrderItemsArchive = "CREATE TABLE IF NOT EXISTS {$tableOrderItemsArchive} LIKE {$tableOrderItems}";
$resultCreateOrderItemsArchive = $wpdb->query( $sqlCreateOrderItemsArchive );
if( !$resultCreateOrderItemsArchive ){
    error_log($wpdb->last_error, 0);
}

$sqlCreateOrderItemMetaArchive = "CREATE TABLE IF NOT EXISTS {$tableOrderItemMetaArchive} LIKE {$tableOrderItemMeta}";
$resultCreateOrderItemMetaArchive = $wpdb->query( $sqlCreateOrderItemMetaArchive );
if( !$resultCreateOrderItemMetaArchive ){
    error_log($wpdb->last_error, 0);
}


// select shop_orders over 200 days old from posts table
$sqlPosts = $wpdb->prepare( "SELECT * FROM {$tablePosts} WHERE post_type = 'shop_order' AND post_date < %s", $expirydate);
$resultPosts = $wpdb->get_results( $sqlPosts );

if( count($resultPosts) > 0 ){
	foreach($resultPosts as $order){
		// begin transaction
		$wpdb->query('START TRANSACTION');

		// insert posts data into posts_archive table
		$dataPosts = json_decode(json_encode($order), true);
		$resultArchivePosts = $wpdb->insert($tablePostsArchive, $dataPosts);
		
		// insert postmeta data into postmeta_archive table
		$resultArchivePostMeta = true;
		$sqlPostMeta = $wpdb->prepare( "SELECT * FROM {$tablePostMeta} WHERE post_id = '%d'", $order->ID);
		$resultPostMeta = $wpdb->get_results( $sqlPostMeta );

		if(count($resultPostMeta) > 0){
			$sqlArchivePostMeta = "INSERT INTO {$tablePostMetaArchive} (meta_id, post_id, meta_key, meta_value) VALUES";

			foreach($resultPostMeta as $meta){
				$escMetaKey = esc_sql($meta->meta_key);
				$escMetaValue = esc_sql($meta->meta_value);
				$sqlArchivePostMeta.= ("('{$meta->meta_id}', '{$meta->post_id}', '{$escMetaKey}', '{$escMetaValue}'),");
			}
			$sqlArchivePostMeta = rtrim($sqlArchivePostMeta, ",");
			
			$resultArchivePostMeta = $wpdb->query($sqlArchivePostMeta);
		}

		// insert order_items data into order_items_archive table
		$resultArchiveOrderItems = true;
		$resultArchiveOrderItemMeta = true;
		$resultDeleteOrderItemMeta = true;
		$sqlOrderItems = $wpdb->prepare( "SELECT * FROM {$tableOrderItems} WHERE order_id = '%d'", $order->ID);
		$resultOrderItems = $wpdb->get_results( $sqlOrderItems );

		
		if(count($resultOrderItems) > 0){
			
			$sqlArchiveOrderItems = "INSERT INTO {$tableOrderItemsArchive} (order_item_id, order_item_name, order_item_type, order_id) VALUES";

			// if order items found, prepare statement to archive
			foreach($resultOrderItems as $item){
				$escItemName = esc_sql($item->order_item_name);
				$escItemType = esc_sql($item->order_item_type);
				$sqlArchiveOrderItems.= ("('{$item->order_item_id}', '{$escItemName}', '{$escItemType}', '{$item->order_id}'),");
				
				// check to insert order_itemmeta data into order_itemmeta_archive table
				$sqlOrderItemMeta = $wpdb->prepare( "SELECT * FROM {$tableOrderItemMeta} WHERE order_item_id = '%d'", $item->order_item_id);
				$resultOrderItemMeta = $wpdb->get_results( $sqlOrderItemMeta );
				if(count($resultOrderItemMeta) > 0){
					
					$sqlArchiveOrderItemMeta = "INSERT INTO {$tableOrderItemMetaArchive} (meta_id, order_item_id, meta_key, meta_value) VALUES";
					
					// if order itemmeta rows found, prepare statement to archive
					foreach($resultOrderItemMeta as $itemMeta){
						$escItemMetaKey = esc_sql($itemMeta->meta_key);
						$escItemMetaValue = esc_sql($itemMeta->meta_value);
						$sqlArchiveOrderItemMeta.= ("('{$itemMeta->meta_id}', '{$itemMeta->order_item_id}', '{$escItemMetaKey}', '{$escItemMetaValue}'),");
					}
					$sqlArchiveOrderItemMeta = rtrim($sqlArchiveOrderItemMeta, ",");
			
					// if archiving one set of order item meta data fails, whole order archive should fail
					$resultArchiveOrderItemMetaSingle = $wpdb->query($sqlArchiveOrderItemMeta);
					if($resultArchiveOrderItemMetaSingle == false){
						$resultArchiveOrderItemMeta == false;
					}
					
					// deleting order items meta
					// if deleting one set of order item meta data fails, whole order archive should fail
					$resultDeleteOrderItemMetaSingle = $wpdb->delete( $tableOrderItemMeta, array( 'order_item_id' => $item->order_item_id ) );
					if($resultDeleteOrderItemMetaSingle == false){
						$resultDeleteOrderItemMeta == false;
					}
				}
			}
			$sqlArchiveOrderItems = rtrim($sqlArchiveOrderItems, ",");
			
			$resultArchiveOrderItems = $wpdb->query($sqlArchiveOrderItems);
		}

		
		// delete shop order from post and postmeta tables
		$resultDeletePost = $wpdb->delete( $tablePosts, array( 'ID' => $order->ID ) );
		$resultDeletePostMeta = $wpdb->delete( $tablePostMeta, array( 'post_id' => $order->ID ) );
		$resultDeleteOrderItems = $wpdb->delete( $tableOrderItems, array( 'order_id' => $order->ID ) );
		
		// check results of all queries
		if(
			$resultArchivePosts && $resultArchivePostMeta && $resultDeletePost && $resultDeletePostMeta
			&& $resultArchiveOrderItems && $resultArchiveOrderItemMeta && $resultDeleteOrderItems && $resultDeleteOrderItemMeta
		) {
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
// error_log("LOG: Database Archive Complete!", 0);
