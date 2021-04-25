<?php
$paypal_currency = $pt->config->paypal_currency;
$requests = array('initialize','paid');
if (!IS_LOGGED) {
	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '1',
            'error_text' => 'Not logged in'
        )
	);
}
elseif (empty($_POST['request']) || (!empty($_POST['request']) && !in_array($_POST['request'], $requests))) {
	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '4',
            'error_text' => 'request can not be empty'
        )
	);
}
else{
	$types = array('pro','subscribe','buy_video','wallet');
	if ($_POST['request'] == 'initialize' && !empty($_POST['type']) && in_array($_POST['type'], $types)) {
		$price = 0;
		if ($_POST['type'] == 'pro') {
			$price = intval($pt->config->pro_pkg_price);
			$callback_url = PT_Link("aj/go_pro/paid?amount=".$price);
		}
		elseif ($_POST['type'] == 'subscribe') {
			if (empty($_POST['subscribe_id']) || !is_numeric($_POST['subscribe_id']) || $_POST['subscribe_id'] < 1) {
				$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '4',
			            'error_text' => 'subscribe_id can not be empty'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
			}
			$user_id = PT_Secure($_POST['subscribe_id']);
			$user = PT_UserData($user_id);
			$price = $user->subscriber_price;
	    	$callback_url = PT_Link("aj/go_pro/paid?subscribe_id=".$user_id);
		}
		elseif ($_POST['type'] == 'buy_video') {
			if (empty($_POST['video_id']) || !is_numeric($_POST['video_id']) || $_POST['video_id'] < 1) {
				$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '4',
			            'error_text' => 'video_id can not be empty'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
			}
			$video = PT_GetVideoByID($_POST['video_id'], 0,0,2);
			if (empty($video)) {
				$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '5',
			            'error_text' => 'video not found'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
			}
			if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent' && !empty($video->rent_price)) {
				$price = $video->rent_price;
				$text = "&pay_type=rent";
			}
			else{
				$price = $video->sell_video;
			}
			$callback_url = PT_Link("aj/go_pro/buy_video?video_id=".$video->id.$text);
		}
		elseif ($_POST['type'] == 'wallet') {
			if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] < 1) {
				$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '4',
			            'error_text' => 'amount can not be empty'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
			}
			$price = PT_Secure($_POST['amount']);
			$callback_url = PT_Link("aj/wallet/wallet_paid?amount=".$price);
		}

		require_once 'assets/import/Paysera.php';

	    $request = WebToPay::redirectToPayment(array(
		    'projectid'     => $pt->config->paysera_project_id,
		    'sign_password' => $pt->config->paysera_sign_password,
		    'orderid'       => rand(111111,999999),
		    'amount'        => $price,
		    'currency'      => $pt->config->payment_currency,
		    'country'       => 'LT',
		    'accepturl'     => $callback_url,
		    'cancelurl'     => $callback_url,
		    'callbackurl'   => $callback_url,
		    'test'          => $pt->config->paysera_mode,
		));
		$response_data     = array(
	        'api_status'   => '200',
	        'api_version'  => $api_version,
	        'url' => $request
	    );
	}
	elseif ($_POST['request'] == 'paid' && !empty($_POST['type']) && in_array($_POST['type'], $types)) {
		require_once 'assets/import/Paysera.php';
		try {
	        $response = WebToPay::checkResponse($_GET, array(
	            'projectid'     => $pt->config->paysera_project_id,
	            'sign_password' => $pt->config->paysera_sign_password,
	        ));
	 
	        // if ($response['test'] !== '0') {
	        //     throw new Exception('Testing, real payment was not made');
	        // }
	        if ($response['type'] !== 'macro') {
	        	$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '5',
			            'error_text' => 'something went wrong'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
	            //throw new Exception('Only macro payment callbacks are accepted');
	        }
	        $amount = $response['amount'];
	        $currency = $response['currency'];

	        if ($currency != $pt->config->payment_currency) {
	        	$response_data    = array(
				    'api_status'  => '400',
				    'api_version' => $api_version,
				    'errors' => array(
			            'error_id' => '5',
			            'error_text' => 'something went wrong'
			        )
				);
				echo json_encode($response_data, JSON_PRETTY_PRINT);
    			exit();
	        }
	        else{
	        	if ($_POST['type'] == 'pro') {
	        		$update = array('is_pro' => 1,'verified' => 1);
				    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
				    if ($go_pro === true) {
				    	$payment_data         = array(
				    		'user_id' => $pt->user->id,
				    		'type'    => 'pro',
				    		'amount'  => $sum,
				    		'date'    => date('n') . '/' . date('Y'),
				    		'expire'  => strtotime("+30 days")
				    	);

				    	$db->insert(T_PAYMENTS,$payment_data);
				    	$db->where('user_id',$pt->user->id)->update(T_VIDEOS,array('featured' => 1));
				    	$_SESSION['upgraded'] = true;
				    	$response_data     = array(
					        'api_status'   => '200',
					        'api_version'  => $api_version,
					        'message' => 'paid successful'
					    );
				    }
				    else{
				    	$response_data    = array(
						    'api_status'  => '400',
						    'api_version' => $api_version,
						    'errors' => array(
					            'error_id' => '6',
					            'error_text' => 'you already pro'
					        )
						);
				    }
	        	}
	        	elseif ($_POST['type'] == 'subscribe') {
	        		$user_id       = (!empty($_POST['subscribe_id']) && is_numeric($_POST['subscribe_id'])) ? PT_Secure($_POST['subscribe_id']) : 0;

					$user = PT_UserData($user_id);
			    	if (!empty($user) && $user->subscriber_price > 0) {

			    		$admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
			    		$paypal_currency = $paypal_currency.'_PERCENT';
			    		$payment_data         = array(
				    		'user_id' => $user_id,
				    		'video_id'    => 0,
				    		'paid_id'  => $pt->user->id,
				    		'amount'    => $user->subscriber_price,
				    		'admin_com'    => $pt->config->admin_com_subscribers,
				    		'currency'    => $paypal_currency,
				    		'time'  => time(),
				    		'type' => 'subscribe'
				    	);
				    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
				    	$balance = $user->subscriber_price - $admin__com;
				    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");
				    	$insert_data         = array(
				            'user_id' => $user_id,
				            'subscriber_id' => $pt->user->id,
				            'time' => time(),
				            'active' => 1
				        );
				        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
				        if ($create_subscription) {

				            $notif_data = array(
				                'notifier_id' => $pt->user->id,
				                'recipient_id' => $user_id,
				                'type' => 'subscribed_u',
				                'url' => ('@' . $pt->user->username),
				                'time' => time()
				            );

				            pt_notify($notif_data);
				        }
				    	$response_data     = array(
					        'api_status'   => '200',
					        'api_version'  => $api_version,
					        'message' => 'paid successful'
					    );
			    	}
			    	else{
			    		$response_data    = array(
						    'api_status'  => '400',
						    'api_version' => $api_version,
						    'errors' => array(
					            'error_id' => '6',
					            'error_text' => 'user not found'
					        )
						);
			    	}
	        	}
	        	elseif ($_POST['type'] == 'buy_video') {
	        		$video_id       = (!empty($_POST['video_id']) && is_numeric($_POST['video_id'])) ? PT_Secure($_POST['video_id']) : 0;

				    if (!empty($video_id)) {
				    	$video = PT_GetVideoByID($video_id, 0,0,2);
				    	if (!empty($video)) {
							$notify_sent = false;
				    		if (!empty($video->is_movie)) {

				    			$payment_data         = array(
						    		'user_id' => $video->user_id,
						    		'video_id'    => $video->id,
						    		'paid_id'  => $pt->user->id,
						    		'admin_com'    => 0,
						    		'currency'    => $paypal_currency,
						    		'time'  => time()
						    	);
						    	if (!empty($_GET['pay_type']) && $_GET['pay_type'] == 'rent') {
					    			$payment_data['type'] = 'rent';
					    			$total = $video->rent_price;
					    		}
					    		else{
					    			$total = $video->sell_video;
					    		}
					    		$payment_data['amount'] = $total;
					    		$db->insert(T_VIDEOS_TRSNS,$payment_data);
				    		}
				    		else{

					    		if (!empty($_GET['pay_type']) && $_GET['pay_type'] == 'rent') {
					    			$admin__com = $pt->config->admin_com_rent_videos;
						    		if ($pt->config->com_type == 1) {
						    			$admin__com = ($pt->config->admin_com_rent_videos * $video->rent_price)/100;
						    			$paypal_currency = $paypal_currency.'_PERCENT';
						    		}
						    		$payment_data         = array(
							    		'user_id' => $video->user_id,
							    		'video_id'    => $video->id,
							    		'paid_id'  => $pt->user->id,
							    		'amount'    => $video->rent_price,
							    		'admin_com'    => $pt->config->admin_com_rent_videos,
							    		'currency'    => $paypal_currency,
							    		'time'  => time(),
							    		'type' => 'rent'
							    	);
							    	$balance = $video->rent_price - $admin__com;
					    		}
					    		else{
					    			$admin__com = $pt->config->admin_com_sell_videos;
						    		if ($pt->config->com_type == 1) {
						    			$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
						    			$paypal_currency = $paypal_currency.'_PERCENT';
						    		}

						    		$payment_data         = array(
							    		'user_id' => $video->user_id,
							    		'video_id'    => $video->id,
							    		'paid_id'  => $pt->user->id,
							    		'amount'    => $video->sell_video,
							    		'admin_com'    => $pt->config->admin_com_sell_videos,
							    		'currency'    => $paypal_currency,
							    		'time'  => time()
							    	);
							    	$balance = $video->sell_video - $admin__com;

					    		}
						    		
						    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
						    	
						    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' , `verified` = 1 WHERE `id` = '".$video->user_id."'");
						    }
						    if ($notify_sent == false) {
						    	$uniq_id = $video->video_id;
				                $notif_data = array(
				                    'notifier_id' => $pt->user->id,
				                    'recipient_id' => $video->user_id,
				                    'type' => 'paid_to_see',
				                    'url' => "watch/$uniq_id",
				                    'video_id' => $video->id,
				                    'time' => time()
				                );
				                
				                pt_notify($notif_data);
						    }
						    $response_data     = array(
						        'api_status'   => '200',
						        'api_version'  => $api_version,
						        'message' => 'paid successful'
						    );
				    	}
				    	else{
				    		$response_data    = array(
							    'api_status'  => '400',
							    'api_version' => $api_version,
							    'errors' => array(
						            'error_id' => '7',
						            'error_text' => 'video not found'
						        )
							);
				    	}
				    }
				    else{
				    	$response_data    = array(
						    'api_status'  => '400',
						    'api_version' => $api_version,
						    'errors' => array(
					            'error_id' => '6',
					            'error_text' => 'video_id can not be empty'
					        )
						);
				    }
	        	}
	        	elseif ($_POST['type'] == 'wallet') {
	        		$db->where('id',$pt->user->id)->update(T_USERS,array('wallet' => $db->inc($amount)));
					$payment_data         = array(
			            'user_id' => $pt->user->id,
			            'paid_id'  => $pt->user->id,
			            'admin_com'    => 0,
			            'currency'    => $pt->config->payment_currency,
			            'time'  => time(),
			            'amount' => $amount,
			            'type' => 'ad'
			        );
			        $db->insert(T_VIDEOS_TRSNS,$payment_data);
				    $response_data     = array(
				        'api_status'   => '200',
				        'api_version'  => $api_version,
				        'message' => 'paid successful'
				    );
	        	}
		        	
	        }
		} catch (Exception $e) {
		    $response_data    = array(
			    'api_status'  => '400',
			    'api_version' => $api_version,
			    'errors' => array(
		            'error_id' => '5',
		            'error_text' => 'something went wrong'
		        )
			);
		}
	}
}