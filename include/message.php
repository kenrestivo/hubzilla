<?php /** @file */

/* Private Message backend API */

require_once('include/crypto.php');
require_once('include/attach.php');

// send a private message
	

function send_message($uid = 0, $recipient='', $body='', $subject='', $replyto='',$expires = ''){ 

	$ret = array('success' => false);

	$a = get_app();

	if(! $recipient) {
		$ret['message'] = t('No recipient provided.');
		return $ret;
	}
	
	if(! strlen($subject))
		$subject = t('[no subject]');

//	if(! $expires)
//		$expires = NULL_DATE;
//	else
//		$expires = datetime_convert(date_default_timezone_get(),'UTC',$expires);




	if($uid) {
		$r = q("select * from channel where channel_id = %d limit 1",
			intval($uid)
		);
		if($r)
			$channel = $r[0];
	}
	else {
		$channel = get_app()->get_channel();
	}

	if(! $channel) {
		$ret['message'] = t('Unable to determine sender.');
		return $ret;
	}


	// look for any existing conversation structure


	if(strlen($replyto)) {
		$r = q("select convid from mail where channel_id = %d and ( mid = '%s' or parent_mid = '%s' ) limit 1",
			intval(local_channel()),
			dbesc($replyto),
			dbesc($replyto)
		);
		if($r)
			$convid = $r[0]['convid'];
	}		

	if(! $convid) {

		// create a new conversation

		$conv_guid = random_string();

		$recip = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($recipient)
		);
		if($recip)
			$recip_handle = $recip[0]['xchan_addr'];

		$sender_handle = $channel['channel_address'] . '@' . get_app()->get_hostname();

		$handles = $recip_handle . ';' . $sender_handle;

		if($subject)
			$nsubject = str_rot47(base64url_encode($subject));

		$r = q("insert into conv (uid,guid,creator,created,updated,subject,recips) values(%d, '%s', '%s', '%s', '%s', '%s', '%s') ",
			intval(local_channel()),
			dbesc($conv_guid),
			dbesc($sender_handle),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc($nsubject),
			dbesc($handles)
		);

		$r = q("select * from conv where guid = '%s' and uid = %d limit 1",
			dbesc($conv_guid),
			intval(local_channel())
		);
		if($r)
			$convid = $r[0]['id'];
	}

	if(! $convid) {
		$ret['message'] = 'conversation not found';
		return $ret;
	}


	// generate a unique message_id

	do {
		$dups = false;
		$hash = random_string();

		$mid = $hash . '@' . get_app()->get_hostname();

		$r = q("SELECT id FROM mail WHERE mid = '%s' LIMIT 1",
			dbesc($mid));
		if(count($r))
			$dups = true;
	} while($dups == true);


	if(! strlen($replyto)) {
		$replyto = $mid;
	}

	/**
	 *
	 * When a photo was uploaded into the message using the (profile wall) ajax 
	 * uploader, The permissions are initially set to disallow anybody but the
	 * owner from seeing it. This is because the permissions may not yet have been
	 * set for the post. If it's private, the photo permissions should be set
	 * appropriately. But we didn't know the final permissions on the post until
	 * now. So now we'll look for links of uploaded messages that are in the
	 * post and set them to the same permissions as the post itself.
	 *
	 */

	$match = null;
	$images = null;
	if(preg_match_all("/\[zmg\](.*?)\[\/zmg\]/",((strpos($body,'[/crypt]')) ? $_POST['media_str'] : $body),$match))
		$images = $match[1];

	$match = false;

	if(preg_match_all("/\[attachment\](.*?)\[\/attachment\]/",((strpos($body,'[/crypt]')) ? $_POST['media_str'] : $body),$match))
		$attaches = $match[1];

	$attachments = '';

	if(preg_match_all('/(\[attachment\](.*?)\[\/attachment\])/',$body,$match)) {
		$attachments = array();
		foreach($match[2] as $mtch) {
			$hash = substr($mtch,0,strpos($mtch,','));
			$rev = intval(substr($mtch,strpos($mtch,',')));
			$r = attach_by_hash_nodata($hash,$rev);
			if($r['success']) {
				$attachments[] = array(
					'href'     => $a->get_baseurl() . '/attach/' . $r['data']['hash'],
					'length'   =>  $r['data']['filesize'],
					'type'     => $r['data']['filetype'],
					'title'    => urlencode($r['data']['filename']),
					'revision' => $r['data']['revision']
				);
			}
			$body = str_replace($match[1],'',$body);
		}
	}

	$jattach = (($attachments) ? json_encode($attachments) : '');

	if($subject)
		$subject = str_rot47(base64url_encode($subject));
	if($body)
		$body  = str_rot47(base64url_encode($body));
	


	$r = q("INSERT INTO mail ( account_id, convid, mail_obscured, channel_id, from_xchan, to_xchan, title, body, attach, mid, parent_mid, created, expires )
		VALUES ( %d, %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )",
		intval($channel['channel_account_id']),
		intval($convid),
		intval(1),
		intval($channel['channel_id']),
		dbesc($channel['channel_hash']),
		dbesc($recipient),
		dbesc($subject),
		dbesc($body),
		dbesc($jattach),
		dbesc($mid),
		dbesc($replyto),
		dbesc(datetime_convert()),
		dbescdate($expires)
	);

	// verify the save

	$r = q("SELECT * FROM mail WHERE mid = '%s' and channel_id = %d LIMIT 1",
		dbesc($mid),
		intval($channel['channel_id'])
	);
	if($r)
		$post_id = $r[0]['id'];
	else {
		$ret['message'] = t('Stored post could not be verified.');
		return $ret;
	}

	if(count($images)) {
		foreach($images as $image) {
			if(! stristr($image,$a->get_baseurl() . '/photo/'))
				continue;
			$image_uri = substr($image,strrpos($image,'/') + 1);
			$image_uri = substr($image_uri,0, strpos($image_uri,'-'));
			$r = q("UPDATE photo SET allow_cid = '%s' WHERE resource_id = '%s' AND uid = %d and allow_cid = '%s'",
				dbesc('<' . $recipient . '>'),
				dbesc($image_uri),
				intval($channel['channel_id']),
				dbesc('<' . $channel['channel_hash'] . '>')
			); 
			$r = q("UPDATE attach SET allow_cid = '%s' WHERE hash = '%s' AND is_photo = 1 and uid = %d and allow_cid = '%s'",
				dbesc('<' . $recipient . '>'),
				dbesc($image_uri),
				intval($channel['channel_id']),
				dbesc('<' . $channel['channel_hash'] . '>')
			); 
		}
	}
	
	if($attaches) {
		foreach($attaches as $attach) {
			$hash = substr($attach,0,strpos($attach,','));
			$rev = intval(substr($attach,strpos($attach,',')));
			attach_store($channel,$observer_hash,$options = 'update', array(
				'hash'      => $hash,
				'revision'  => $rev,
				'allow_cid' => '<' . $recipient . '>',

			));
		}
	}

	proc_run('php','include/notifier.php','mail',$post_id);

	$ret['success'] = true;
	$ret['message_item'] = intval($post_id);
	return $ret;

}

function private_messages_list($uid, $mailbox = '', $start = 0, $numitems = 0) {

	$where = '';
	$limit = '';

	$t0 = dba_timer();

	if($numitems)
		$limit = " LIMIT " . intval($numitems) . " OFFSET " . intval($start);
		
	if($mailbox !== '') {
		$x = q("select channel_hash from channel where channel_id = %d limit 1",
			intval($uid)
		);
		if(! $x)
			return array();

		$channel_hash = dbesc($x[0]['channel_hash']);
		$local_channel = intval(local_channel());

		switch($mailbox) {

			case 'inbox':
				$sql = "SELECT * FROM mail WHERE channel_id = $local_channel AND from_xchan != '$channel_hash' ORDER BY created DESC $limit";
				break;

			case 'outbox':
				$sql = "SELECT * FROM mail WHERE channel_id = $local_channel AND from_xchan = '$channel_hash' ORDER BY created DESC $limit";
				break;

			case 'combined':
				$sql = "SELECT * FROM ( SELECT * FROM mail WHERE channel_id = $local_channel ORDER BY created DESC $limit ) AS temp_table GROUP BY parent_mid ORDER BY created DESC";
				break;

		}

	}

	$r = q($sql);

	if(! $r) {
		return array();
	}

	$chans = array();
	foreach($r as $rr) {
		$s = "'" . dbesc(trim($rr['from_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
		$s = "'" . dbesc(trim($rr['to_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
 	}

	$c = q("select * from xchan where xchan_hash in (" . implode(',',$chans) . ")");

	foreach($r as $k => $rr) {
		$r[$k]['from'] = find_xchan_in_array($rr['from_xchan'],$c);
		$r[$k]['to']   = find_xchan_in_array($rr['to_xchan'],$c);
		$r[$k]['seen'] = intval($rr['mail_seen']);
		if(intval($r[$k]['mail_obscured'])) {
			if($r[$k]['title'])
				$r[$k]['title'] = base64url_decode(str_rot47($r[$k]['title']));
			if($r[$k]['body'])
				$r[$k]['body'] = base64url_decode(str_rot47($r[$k]['body']));
		}
	}

	return $r;
}



function private_messages_fetch_message($channel_id, $messageitem_id, $updateseen = false) {

	$messages = q("select * from mail where id = %d and channel_id = %d order by created asc",
		dbesc($messageitem_id),
		intval($channel_id)
	);

	if(! $messages)
		return array();

	$chans = array();
	foreach($messages as $rr) {
		$s = "'" . dbesc(trim($rr['from_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
		$s = "'" . dbesc(trim($rr['to_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
	}

	$c = q("select * from xchan where xchan_hash in (" . implode(',',$chans) . ")");

	foreach($messages as $k => $message) {
		$messages[$k]['from'] = find_xchan_in_array($message['from_xchan'],$c);
		$messages[$k]['to']   = find_xchan_in_array($message['to_xchan'],$c);
		if(intval($messages[$k]['mail_obscured'])) {
			if($messages[$k]['title'])
				$messages[$k]['title'] = base64url_decode(str_rot47($messages[$k]['title']));
			if($messages[$k]['body'])
				$messages[$k]['body'] = base64url_decode(str_rot47($messages[$k]['body']));
		}
	}


	if($updateseen) {
		$r = q("UPDATE `mail` SET mail_seen = 1 where mail_seen = 0 and id = %d AND channel_id = %d",
			dbesc($messageitem_id),
			intval($channel_id)
		);
	}

	return $messages;

}


function private_messages_drop($channel_id, $messageitem_id, $drop_conversation = false) {

	if($drop_conversation) {
		// find the parent_id
		$p = q("SELECT parent_mid FROM mail WHERE id = %d AND channel_id = %d LIMIT 1",
			intval($messageitem_id),
			intval($channel_id)
		);
		if($p) {
			$r = q("DELETE FROM mail WHERE parent_mid = '%s' AND channel_id = %d ",
				dbesc($p[0]['parent_mid']),
				intval($channel_id)
			);
			if($r)
				return true;
		}
	}
	else {
		$r = q("DELETE FROM mail WHERE id = %d AND channel_id = %d",
			intval($messageitem_id),
			intval($channel_id)
		);
		if($r)
			return true;
	}
	return false;
}


function private_messages_fetch_conversation($channel_id, $messageitem_id, $updateseen = false) {

	// find the parent_mid of the message being requested

	$r = q("SELECT parent_mid from mail WHERE channel_id = %d and id = %d limit 1",
		intval($channel_id),
		intval($messageitem_id)
	);

	if(! $r) 
		return array();

	$messages = q("select * from mail where parent_mid = '%s' and channel_id = %d order by created asc",
		dbesc($r[0]['parent_mid']),
		intval($channel_id)
	);

	if(! $messages)
		return array();

	$chans = array();
	foreach($messages as $rr) {
		$s = "'" . dbesc(trim($rr['from_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
		$s = "'" . dbesc(trim($rr['to_xchan'])) . "'";
		if(! in_array($s,$chans))
			$chans[] = $s;
	}


	$c = q("select * from xchan where xchan_hash in (" . implode(',',$chans) . ")");

	foreach($messages as $k => $message) {
		$messages[$k]['from'] = find_xchan_in_array($message['from_xchan'],$c);
		$messages[$k]['to']   = find_xchan_in_array($message['to_xchan'],$c);
		if(intval($messages[$k]['mail_obscured'])) {
			if($messages[$k]['title'])
				$messages[$k]['title'] = base64url_decode(str_rot47($messages[$k]['title']));
			if($messages[$k]['body'])
				$messages[$k]['body'] = base64url_decode(str_rot47($messages[$k]['body']));
		}
	}



	if($updateseen) {
		$r = q("UPDATE `mail` SET mail_seen = 1 where mail_seen = 0 and parent_mid = '%s' AND channel_id = %d",
			dbesc($r[0]['parent_mid']),
			intval($channel_id)
		);
	}
	
	return $messages;

}
