<?php

function vendor_document_send_notifications() {

    $documents = VendorDocuments::instance()->findAll(array('conditions' =>  "`status` != 'Pending' AND (notify = 'No' OR notify = '') AND (document_expiry_date = CURDATE() + INTERVAL 60 DAY OR document_expiry_date = CURDATE() + INTERVAL 30 DAY OR document_expiry_date < CURDATE())"));
    
    if (empty($documents)) {
        return;
    }

    // Reminder emails (60/30-day): assigned contact + 2 additional recipient
    $reminderExtraEmails = [
        'a.maksotov@globalinklogistics.com',
        'a.gretchenko@globalinklogistics.com',
    ];

    // Expired emails: assigned contact + 2 additional recipients (escalation)
    $expiredExtraEmails = [
        'a.maksotov@globalinklogistics.com',
        'a.gretchenko@globalinklogistics.com',
    ];

    // Batch-collect IDs to avoid N+1 queries
    $contactIds = array();
    $objectIds  = array();
    foreach ($documents as $document) {
        $contactIds[] = (int) $document->getRecordColumn('assigned_to_contact_id');
        $objectIds[]  = (int) $document->getRecordColumn('object_id');
    }
    $contactIds = array_unique($contactIds);
    $objectIds  = array_unique($objectIds);

    $contactIdList = implode(',', $contactIds ?: array(0));
    $objectIdList  = implode(',', $objectIds ?: array(0));

    // One query for all contact names
    $usersById = array();
    foreach (DB::executeAll("SELECT id, name FROM " . TABLE_PREFIX . "objects
                              WHERE id IN ($contactIdList) AND object_type_id = '15'") as $row) {
        $usersById[$row['id']] = $row['name'];
    }

    // One query for all main emails
    $emailsById = array();
    foreach (DB::executeAll("SELECT contact_id, email_address FROM " . TABLE_PREFIX . "contact_emails
                              WHERE contact_id IN ($contactIdList) AND is_main = '1' AND email_type_id = '2'") as $row) {
        $emailsById[$row['contact_id']] = $row['email_address'];
    }

    // One query for all document names
    $docInfoById = array();
    foreach (DB::executeAll("SELECT id, name FROM " . TABLE_PREFIX . "objects
                              WHERE id IN ($objectIdList) AND object_type_id = '72'") as $row) {
        $docInfoById[$row['id']] = $row['name'];
    }

    $today = new DateTime('today');

    foreach ($documents as $document) {

        $contactId = (int) $document->getRecordColumn('assigned_to_contact_id');
        $objectId  = (int) $document->getRecordColumn('object_id');
        $expiryRaw = $document->getRecordColumn('document_expiry_date');

        // Guard against missing lookups
        if (empty($usersById[$contactId]) || empty($emailsById[$contactId]) || empty($docInfoById[$objectId]) || empty($expiryRaw)) {
            // Log and skip this one instead of crashing the whole batch
            error_log("Skipping vendor doc notification: missing data for contact $contactId / object $objectId");
            continue;
        }

        try {
            $expiry = new DateTime($expiryRaw);
        } catch (Exception $e) {
            error_log("Invalid expiry date for object $objectId: " . $expiryRaw);
            continue;
        }

        $interval = $today->diff($expiry);
        $remainingDays = (int) $interval->format('%r%a');
        $isExpired = $remainingDays <= 0;

        $assigned_user       = $usersById[$contactId];
        $assigned_user_email = $emailsById[$contactId];
        $document_name       = $docInfoById[$objectId];

        $subject = $isExpired
            ? 'Action Required - Vendor Document Has Expired'
            : 'Vendor Document Expiry Reminder - ' . $remainingDays . ' Days Remaining';

        $body = '<html>';		
			$body .= 'Dear '.$assigned_user.', <br /><br />';
            if ($isExpired) {
                $body .= 'Please be advised that the following document associated with your vendor profile has <b>expired:</b><br /><br />';
            } else {
                $body .= 'This is a reminder that the following document submitted for your vendor profile is due to expire in <b>'.$remainingDays.' days:</b><br /><br />';
            }
            $body .= '<table style="width:90%; vertical-align: middle; border-collapse:collapse; float:left;">';
				$body .= '<tbody>';
					$body .= '<tr>';
						$body .= '<td style="width:174.8pt; border-style:solid; border-width:0.75pt; padding-right:5.03pt; padding-left:5.03pt; font-family:Arial; color:#0070c0;">Document Name </td><td style="width:174.8pt; border-style:solid; border-width:0.75pt; padding-right:5.03pt; padding-left:5.03pt;">'.$document_name.'</td>';
					$body .= '</tr>';
					$body .= '<tr>';
						$body .= '<td style="width:174.8pt; border-style:solid; border-width:0.75pt; padding-right:5.03pt; padding-left:5.03pt; font-family:Arial; color:#0070c0;">Expiry Date </td><td style="width:174.8pt; border-style:solid; border-width:0.75pt; padding-right:5.03pt; padding-left:5.03pt;">'.$expiryRaw.'</td>';
					$body .= '</tr>';
				$body .= '</tbody>';
			$body .= '</table><br /><br />';
            if ($isExpired) {
                $body .= 'Your vendor compliance status may be affected until a valid replacement document is submitted and approved.';
            } else {
                $body .= 'Please upload the renewed document through the <b>Vendor Self-Service Portal</b> before the current document expires to maintain your vendor compliance status.';
            }
            $body .= '<br /><br />'; 
            $body .= '<b>Required Action:</b><br /><br />';
            if ($isExpired) {
                $body .= 'Please log in to the <b>Vendor Self-Service Portal</b> and upload a valid, renewed document as soon as possible.<br /><br />';
                $body .= 'The replacement document will be subject to review and approval by the Vendor Controller.<br /><br />';
                $body .= 'If you have already submitted a replacement document, please disregard this notification.';
            } else {
                $body .= 'Please log in to the Vendor Self-Service Portal and upload the renewed document for review and approval.<br /><br />';
                $body .= 'If the updated document has already been submitted, please disregard this notification.<br /><br />';
                $body .= 'Thank you for your prompt attention to this matter.';
            }
            $body .= '<br /><br />'; 
            $body .= 'Best regards,<br />';
            $body .= '<b>Vendor Management Team</b>';    
        $body .= '</html>'; 

        $doc_status = 'Expiring Soon';
        if ($isExpired) {
            $doc_status = 'Expired';
        }    
        $update_status = "UPDATE ".TABLE_PREFIX."vendordocuments SET status = '$doc_status', notify = 'Yes' WHERE object_id = ".$objectId;	
        $excute = DB::execute($update_status);
        DB::execute(
			"INSERT INTO ".TABLE_PREFIX."vendordocument_audit (
		 		vendor_document_id, vendor_id, action, field_name, old_value, new_value, comments, action_by, action_by_type, action_date, ip_address) 
		 	VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			[
				$objectId,
				$contactId,
				'FIELD UPDATE',
				'status',
				$document->getRecordColumn('status'),
				$doc_status,
				'Vendor updated status field data',
				'',
				'System',
				date('Y-m-d H:i:s'),
				$_SERVER['REMOTE_ADDR'] ?? ''
			]
		);
        // Cc: extra recipients depending on urgency (2 total for reminders, 3 total for expired)
        $extraEmails = $isExpired ? $expiredExtraEmails : $reminderExtraEmails;
        $toEmails = array_unique(array_merge(['a.awan@globalinklogistics.com'], $extraEmails));

        $success = Notifier::sendEmail($toEmails, [], $subject, $body, 'text/html');    
        if (!$success) {
            error_log("Failed to send vendor doc expiry email to $assigned_user_email for object $objectId");
        }                               
    }
}