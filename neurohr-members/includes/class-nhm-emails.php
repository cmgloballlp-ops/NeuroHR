<?php
/**
 * Emails. Every email is listed in Settings → Emails (Member notices / Admin notices) with
 * on/off, Edit, Send test and Reset to default. Edited texts are stored as
 * email_{key}_subj / email_{key}_body in the settings; the defaults live here.
 * Sending goes through wp_mail(), so the site's own mail setup is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Emails {

	/** key => group, title, info, subj, body. */
	public static function registry() {
		return array(
			// ---------- Member notices ----------
			'welcome'           => array(
				'group' => 'member',
				'title' => __( 'Welcome Email', 'neurohr-members' ),
				'info'  => __( 'After someone creates an account.', 'neurohr-members' ),
				'subj'  => 'Welcome to {site}',
				'body'  => "Hi {name},\n\nThank you for creating your {site} account. You can log in any time at {login_url}.\n\nYour plan: {level}\nYour account: {account_url}\n\nRegards,\n{site}",
			),
			'verify'            => array(
				'group' => 'member',
				'title' => __( 'Confirm Email Address', 'neurohr-members' ),
				'info'  => __( 'After registration, when "New accounts confirm their email" is on. The account works after the link is clicked.', 'neurohr-members' ),
				'subj'  => 'Please confirm your email for {site}',
				'body'  => "Hi {name},\n\nThanks for signing up. Please confirm your email address to activate your account:\n{verify_url}\n\nIf you did not sign up, you can ignore this email.\n\nRegards,\n{site}",
			),
			'verify_second'     => array(
				'group' => 'member',
				'title' => __( 'Confirm Second Email', 'neurohr-members' ),
				'info'  => __( 'A member adds a second email address in My account.', 'neurohr-members' ),
				'subj'  => 'Confirm this email address for {site}',
				'body'  => "Hi {name},\n\nPlease confirm that {site} may send your account emails to this address:\n{verify_url}\n\nIf this was not you, ignore this email.\n\nRegards,\n{site}",
			),
			'reset'             => array(
				'group' => 'member',
				'title' => __( 'Password Reset', 'neurohr-members' ),
				'info'  => __( 'Someone uses "Forgot password?".', 'neurohr-members' ),
				'subj'  => 'Reset your {site} password',
				'body'  => "Hi {name},\n\nSomeone asked to reset the password of your {site} account. If this was not you, ignore this email and nothing will change.\n\nTo choose a new password, open this link:\n{reset_url}\n\nRegards,\n{site}",
			),
			'password_changed'  => array(
				'group' => 'member',
				'title' => __( 'Password Changed', 'neurohr-members' ),
				'info'  => __( 'After a password change, as a security notice.', 'neurohr-members' ),
				'subj'  => 'Your {site} password was changed',
				'body'  => "Hi {name},\n\nThe password of your {site} account was just changed. If you did this, there is nothing else to do.\n\nIf you did not change it, reset your password now: {login_url}\n\nRegards,\n{site}",
			),
			'email_changed'     => array(
				'group' => 'member',
				'title' => __( 'Login Email Changed', 'neurohr-members' ),
				'info'  => __( 'Sent to the old address when the login email is changed.', 'neurohr-members' ),
				'subj'  => 'Your {site} login email was changed',
				'body'  => "Hi {name},\n\nThe login email of your {site} account was changed to {new_email}. If you did not do this, please contact us right away.\n\nRegards,\n{site}",
			),
			'account'           => array(
				'group' => 'member',
				'title' => __( 'Account Created For You', 'neurohr-members' ),
				'info'  => __( 'When you import people or tick "send login details" for a new person. Contains a link to choose a password.', 'neurohr-members' ),
				'subj'  => 'Your {site} account is ready',
				'body'  => "Hi {name},\n\nAn account has been created for you on {site} with this email address. Your plan: {level}.\n\nChoose your password here (the link works once):\n{password_url}\n\nAfterwards you can log in at {login_url}.\n\nRegards,\n{site}",
			),
			'application'       => array(
				'group' => 'member',
				'title' => __( 'Plan Application (invoice attached)', 'neurohr-members' ),
				'info'  => __( 'Someone signs up for or asks for a paid plan. The invoice PDF is attached.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan: invoice {invoice_number}',
				'body'  => "Hi {name},\n\nThank you for choosing the {level} plan. Your invoice {invoice_number} for {invoice_total} is attached (due {invoice_due}).\n\nYou can pay online by card, FPX, GrabPay, bank transfer or DuitNow QR here:\n{pay_url}\n\n{payment_details}\n\nYour {level} plan starts as soon as we receive the payment. You can also download the invoice from your account: {account_url}\n\nRegards,\n{site}",
			),
			'invoice'           => array(
				'group' => 'member',
				'title' => __( 'Invoice', 'neurohr-members' ),
				'info'  => __( 'When you click "Send by email" on an invoice, and for renewal invoices. PDF attached.', 'neurohr-members' ),
				'subj'  => 'Invoice {invoice_number} from {site}',
				'body'  => "Hi {name},\n\nPlease find invoice {invoice_number} for {invoice_total} attached (due {invoice_due}).\n\nYou can pay online by card, FPX, GrabPay, bank transfer or DuitNow QR here:\n{pay_url}\n\n{payment_details}\n\nYou can also download it from your account: {account_url}\n\nRegards,\n{site}",
			),
			'payment'           => array(
				'group' => 'member',
				'title' => __( 'Payment Received', 'neurohr-members' ),
				'info'  => __( 'When an invoice is marked paid. The paid invoice is attached; if it carried a plan, the plan is now active.', 'neurohr-members' ),
				'subj'  => 'Payment received for {invoice_number}',
				'body'  => "Hi {name},\n\nWe have received your payment of {invoice_total} for invoice {invoice_number}. Thank you! The paid invoice is attached.\n\n{plan_line}\n\nYou can log in any time here: {login_url}\n\nRegards,\n{site}",
			),
			'invoice_due_soon'  => array(
				'group' => 'member',
				'title' => __( 'Invoice Due Tomorrow', 'neurohr-members' ),
				'info'  => __( 'The day before a sign-up or upgrade invoice is due. The invoice is attached.', 'neurohr-members' ),
				'subj'  => 'A friendly reminder about your {level} plan',
				'body'  => "Hi {name},\n\nJust a friendly reminder that invoice {invoice_number} for your {level} plan ({invoice_total}) is due tomorrow.\n\nYou can pay online by card, FPX, GrabPay, bank transfer or DuitNow QR here:\n{pay_url}\n\nIf you have already paid, thank you, and please ignore this email.\n\nRegards,\n{site}",
			),
			'invoice_expired'   => array(
				'group' => 'member',
				'title' => __( 'Invoice Cancelled (not paid)', 'neurohr-members' ),
				'info'  => __( 'The day after a sign-up or upgrade invoice was due and not paid. A new account that never paid is removed, so the person can sign up again.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan request has been cancelled',
				'body'  => "Hi {name},\n\nWe did not receive the payment for invoice {invoice_number} ({invoice_total}), so we have cancelled this {level} plan request.\n\nWhenever you are ready, you are very welcome to choose a plan again here:\n{renew_url}\n\nIf you think this is a mistake, just reply to this email and we will help.\n\nRegards,\n{site}",
			),
			'proof_received'    => array(
				'group' => 'member',
				'title' => __( 'Payment Details Received', 'neurohr-members' ),
				'info'  => __( 'Someone sends a bank-transfer or DuitNow QR payment with a slip. The plan starts when you approve it.', 'neurohr-members' ),
				'subj'  => 'We received your payment details for {invoice_number}',
				'body'  => "Hi {name},\n\nThank you! We received your payment details for invoice {invoice_number}:\n\nMethod: {method}\nAmount: {paid_amount}\nPaid on: {paid_at}\nReference: {reference}\n\nWe will confirm it within 3 working days, and you will get another email as soon as your plan starts.\n\nRegards,\n{site}",
			),
			'proof_rejected'    => array(
				'group' => 'member',
				'title' => __( 'Payment Not Confirmed', 'neurohr-members' ),
				'info'  => __( 'When you reject a bank-transfer or QR payment. {reason} is what you wrote.', 'neurohr-members' ),
				'subj'  => 'About your payment for {invoice_number}',
				'body'  => "Hi {name},\n\nWe could not confirm the payment you sent for invoice {invoice_number} ({invoice_total}):\n\n{reason}\n\nPlease pay again or send the correct details here:\n{pay_url}\n\nIf you think this is a mistake, just reply to this email.\n\nRegards,\n{site}",
			),
			'invite'            => array(
				'group' => 'member',
				'title' => __( 'Company Invitation', 'neurohr-members' ),
				'info'  => __( 'A company owner or you invite someone to a company account.', 'neurohr-members' ),
				'subj'  => '{inviter} invited you to {company} on {site}',
				'body'  => "Hello,\n\n{inviter} has added you to {company} on {site}. As part of {company} you get the {level} plan.\n\nAccept the invitation here (the link is valid for {invite_days} days):\n{invite_url}\n\nIf you already have an account with this email address, log in first and then open the link.\n\nRegards,\n{site}",
			),
			'joined'            => array(
				'group' => 'member',
				'title' => __( 'Added To Company', 'neurohr-members' ),
				'info'  => __( 'To the person, when they join a company account (invitation accepted or added by you).', 'neurohr-members' ),
				'subj'  => 'You now have {level} through {company}',
				'body'  => "Hi {name},\n\nYou are now part of the {company} account on {site} and have the {level} plan{until}.\n\nLog in: {login_url}\n\nRegards,\n{site}",
			),
			'reminder'          => array(
				'group' => 'member',
				'title' => __( 'Plan Ending Soon', 'neurohr-members' ),
				'info'  => __( 'Before the last day (Reminder days below). Company plans: to the company owner. A renewal invoice is attached for recurring plans.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan ends in {days} days',
				'body'  => "Hi {name},\n\nYour {level} plan{for_company} ends on {expiry} ({days} days from today). After that the account goes back to {base_level} and members-only content will be locked.\n\n{renewal_text}\n\nRegards,\n{site}",
			),
			'expired'           => array(
				'group' => 'member',
				'title' => __( 'Plan Ended', 'neurohr-members' ),
				'info'  => __( 'The day after the last day. Company plans: to the company owner.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan has ended',
				'body'  => "Hi {name},\n\nYour {level} plan{for_company} ended on {expiry}. Your account is now {base_level}; you can still log in.\n\nTo renew, reply to this email or visit {renew_url}.\n\nRegards,\n{site}",
			),
			'renew_reminder'    => array(
				'group' => 'member',
				'title' => __( 'Auto-Renew Coming Up', 'neurohr-members' ),
				'info'  => __( '7 and 3 days before a plan with auto-renew on is renewed.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan renews on {renew_date}',
				'body'  => "Hi {name},\n\nJust a heads-up: your {level} plan renews automatically on {renew_date}. We will charge {amount} to your card {card}.\n\nNothing to do if you would like to keep going. If you would rather not renew, you can turn off auto-renew before {renew_date} here:\n{account_url}\n\nRegards,\n{site}",
			),
			'renewed'           => array(
				'group' => 'member',
				'title' => __( 'Plan Renewed', 'neurohr-members' ),
				'info'  => __( 'The card was charged and the plan renewed. The paid invoice is attached.', 'neurohr-members' ),
				'subj'  => 'Your {level} plan is renewed until {expiry}',
				'body'  => "Hi {name},\n\nThank you! Your {level} plan has been renewed and is now active until {expiry}.\n\nWe charged {invoice_total} to your card {card}. The paid invoice {invoice_number} is attached.\n\nManage auto-renew any time: {account_url}\n\nRegards,\n{site}",
			),
			'renew_failed'      => array(
				'group' => 'member',
				'title' => __( 'Auto-Renew Payment Failed', 'neurohr-members' ),
				'info'  => __( 'The first try to charge the card did not work. Access continues; we try again 3 days later.', 'neurohr-members' ),
				'subj'  => 'We couldn\'t renew your {level} plan yet',
				'body'  => "Hi {name},\n\nWe tried to charge {amount} to your card {card} to renew your {level} plan, but it didn't go through.\n\nNo worries, your plan stays active for now and we will try again on {retry_date}. To make sure it works, you can update your card here (we will try the new card straight away):\n{card_url}\n\nRegards,\n{site}",
			),
			'renew_final'       => array(
				'group' => 'member',
				'title' => __( 'Auto-Renew Stopped: Please Pay', 'neurohr-members' ),
				'info'  => __( 'The second try failed too. The member gets the renewal invoice with the payment link; auto-renew is turned off.', 'neurohr-members' ),
				'subj'  => 'Please renew your {level} plan',
				'body'  => "Hi {name},\n\nWe couldn't charge your card {card} for your {level} plan, so auto-renew is now off.\n\nYou can still renew with invoice {invoice_number} ({invoice_total}) here, by card, FPX, bank transfer or DuitNow QR:\n{pay_url}\n\nThe invoice is attached. If you have any questions, just reply to this email.\n\nRegards,\n{site}",
			),
			'card_expiring'     => array(
				'group' => 'member',
				'title' => __( 'Card Expires Before Renewal', 'neurohr-members' ),
				'info'  => __( '30 days before the renewal, when the saved card expires before that day.', 'neurohr-members' ),
				'subj'  => 'Please update your card for {site}',
				'body'  => "Hi {name},\n\nYour {level} plan renews on {renew_date}, but the card on file ({card}) expires before then.\n\nPlease add your new card so your plan renews without a break:\n{card_url}\n\nRegards,\n{site}",
			),
			'refund_received'   => array(
				'group' => 'member',
				'title' => __( 'Refund Request Received', 'neurohr-members' ),
				'info'  => __( 'A member asked for a refund. Access is paused while you review it.', 'neurohr-members' ),
				'subj'  => 'We received your refund request ({invoice_number})',
				'body'  => "Hi {name},\n\nWe received your refund request for invoice {invoice_number} ({invoice_total}).\n\nReason: {reason}\n\nWe will review it and reply by email. While we review it, access to members-only content is paused.\n\nRegards,\n{site}",
			),
			'refund_approved'   => array(
				'group' => 'member',
				'title' => __( 'Refund Approved', 'neurohr-members' ),
				'info'  => __( 'A bank transfer or QR payment refund was approved; you pay it back yourself.', 'neurohr-members' ),
				'subj'  => 'Your refund for {invoice_number} is approved',
				'body'  => "Hi {name},\n\nYour refund of {amount} for invoice {invoice_number} is approved. We will transfer it back to you by {complete_by}, and email you the Refund Note once it is done.\n\nYour {level} plan has ended. You can still log in with your free account.\n\nRegards,\n{site}",
			),
			'refund_done'       => array(
				'group' => 'member',
				'title' => __( 'Refund Completed', 'neurohr-members' ),
				'info'  => __( 'The money was paid back. The Refund Note (PDF) is attached.', 'neurohr-members' ),
				'subj'  => 'Your refund of {amount} ({refund_number})',
				'body'  => "Hi {name},\n\nWe have refunded {amount} for invoice {invoice_number}. The Refund Note {refund_number} is attached.\n\nCard refunds usually show on your statement within 5 to 10 working days.\n\nYour {level} plan has ended. You are welcome back any time.\n\nRegards,\n{site}",
			),
			'refund_rejected'   => array(
				'group' => 'member',
				'title' => __( 'Refund Not Approved', 'neurohr-members' ),
				'info'  => __( 'You did not approve a refund request. Access is back.', 'neurohr-members' ),
				'subj'  => 'About your refund request ({invoice_number})',
				'body'  => "Hi {name},\n\nThank you for your patience. We are not able to approve the refund for invoice {invoice_number}:\n\n{reject_reason}\n\nYour plan is active again and you have full access. If you have any questions, just reply to this email.\n\nRegards,\n{site}",
			),
			// ---------- Admin notices ----------
			'admin_refund_request' => array(
				'group' => 'admin',
				'title' => __( 'New Refund Request', 'neurohr-members' ),
				'info'  => __( 'A member asked for a refund. Review it under Members → Payments → Refund requests.', 'neurohr-members' ),
				'subj'  => '[{site}] Refund request: {name} ({invoice_number})',
				'body'  => "{name} ({email}) asked for a refund of invoice {invoice_number} ({invoice_total}).\n\nReason: {reason}\n\nTheir access is paused. Please complete approved refunds by {complete_by}.\n\nReview it here: {refunds_url}",
			),
			'admin_dispute'     => array(
				'group' => 'admin',
				'title' => __( 'Chargeback Opened', 'neurohr-members' ),
				'info'  => __( 'A customer disputed a card payment with their bank. Their access is paused.', 'neurohr-members' ),
				'subj'  => '[{site}] Chargeback: {name} ({amount})',
				'body'  => "A chargeback was opened for {name} ({email}), invoice {invoice_number}, {amount}.\nReason given by the bank: {reason}\n\nTheir access is paused and auto-renew is off. Answer the dispute in Stripe:\n{stripe_url}\n\nTo give access back, open the member: {admin_url}",
			),
			'admin_renew_failed' => array(
				'group' => 'admin',
				'title' => __( 'Auto-Renew Failed', 'neurohr-members' ),
				'info'  => __( 'A card could not be charged twice. The member got the invoice with the payment link.', 'neurohr-members' ),
				'subj'  => '[{site}] Auto-renew failed: {name} ({level})',
				'body'  => "The card of {name} ({email}) could not be charged twice for the {level} plan ({amount}).\n\nLast error: {error}\n\nThe member got invoice {invoice_number} with the payment link. Auto-renew is now off.\n\n{admin_url}",
			),
			'admin_signup'      => array(
				'group' => 'admin',
				'title' => __( 'New Signup', 'neurohr-members' ),
				'info'  => __( 'Someone created an account.', 'neurohr-members' ),
				'subj'  => '[{site}] New account: {name}',
				'body'  => "New account on {site}:\n\nName: {name}\nEmail: {email}\nPlan applied for: {level}\n{fields}\n\nOpen: {admin_url}",
			),
			'admin_application' => array(
				'group' => 'admin',
				'title' => __( 'New Plan Application', 'neurohr-members' ),
				'info'  => __( 'Someone asked for a paid plan; an unpaid invoice was created. Mark it paid when the money arrives.', 'neurohr-members' ),
				'subj'  => '[{site}] {name} applied for {level} ({invoice_number})',
				'body'  => "{name} ({email}) applied for the {level} plan.\n\nInvoice {invoice_number}: {invoice_total}, due {invoice_due}.\n\nWhen the payment arrives, open the invoice and click \"Mark as paid\" — the plan starts automatically:\n{invoice_admin_url}",
			),
			'admin_proof'       => array(
				'group' => 'admin',
				'title' => __( 'Payment to Check', 'neurohr-members' ),
				'info'  => __( 'A member sent a bank-transfer or DuitNow QR payment. The slip is attached. Approve or reject it under Members → Payments.', 'neurohr-members' ),
				'subj'  => '[{site}] Payment to check: {invoice_number} ({paid_amount})',
				'body'  => "{payer} sent a payment for invoice {invoice_number} ({invoice_total}).\n\nMethod: {method}\nAmount: {paid_amount}\nPaid on: {paid_at}\nReference: {reference}\nNote: {payment_note}\n\nThe slip is attached. Approve or reject it here:\n{payments_url}",
			),
			'admin_joined'      => array(
				'group' => 'admin',
				'title' => __( 'Invitation Accepted', 'neurohr-members' ),
				'info'  => __( 'Someone joined a company account.', 'neurohr-members' ),
				'subj'  => '[{site}] {name} joined {company}',
				'body'  => "{name} ({email}) joined {company}. Seats used: {seats}.\n\n{company_admin_url}",
			),
			'admin_left'        => array(
				'group' => 'admin',
				'title' => __( 'Member Left A Company', 'neurohr-members' ),
				'info'  => __( 'Someone left a company account themselves.', 'neurohr-members' ),
				'subj'  => '[{site}] {name} left {company}',
				'body'  => "{name} ({email}) left {company}. Seats used: {seats}.\n\n{company_admin_url}",
			),
			'admin_company_billing' => array(
				'group' => 'admin',
				'title' => __( 'Company Billing Details Changed', 'neurohr-members' ),
				'info'  => __( 'A company owner changed the company\'s billing details in My account.', 'neurohr-members' ),
				'subj'  => '[{site}] {company} changed its billing details',
				'body'  => "{name} ({email}) changed the billing details of {company}:\n\n{changes}\n\nNew invoices use the new details. {company_admin_url}",
			),
			'admin_reminder'    => array(
				'group' => 'admin',
				'title' => __( 'Plan Ending Soon (copy)', 'neurohr-members' ),
				'info'  => __( 'A copy for you whenever a "Plan Ending Soon" email goes out, to follow up renewals.', 'neurohr-members' ),
				'subj'  => '[{site}] {level} of {name} ends {expiry}',
				'body'  => "The {level} plan of {name}{for_company} ({email}) ends on {expiry} ({days} days).\n\n{renewal_text}\n\n{admin_url}",
			),
			'admin_expired'     => array(
				'group' => 'admin',
				'title' => __( 'Plan Ended (copy)', 'neurohr-members' ),
				'info'  => __( 'A copy for you when a plan ends.', 'neurohr-members' ),
				'subj'  => '[{site}] {level} of {name} has ended',
				'body'  => "The {level} plan of {name}{for_company} ({email}) ended on {expiry}.\n\n{admin_url}",
			),
		);
	}

	public static function placeholders() {
		return array(
			'{name}'            => __( "member's name (company emails: owner's name)", 'neurohr-members' ),
			'{email}'           => __( "member's email", 'neurohr-members' ),
			'{level}'           => __( 'plan name', 'neurohr-members' ),
			'{base_level}'      => __( 'free plan name', 'neurohr-members' ),
			'{expiry}'          => __( 'last day', 'neurohr-members' ),
			'{days}'            => __( 'days left', 'neurohr-members' ),
			'{company}'         => __( 'company name', 'neurohr-members' ),
			'{for_company}'     => __( '" for Company X" (empty for a personal plan)', 'neurohr-members' ),
			'{invoice_number}'  => __( 'invoice number', 'neurohr-members' ),
			'{invoice_total}'   => __( 'invoice total, e.g. RM 299.00', 'neurohr-members' ),
			'{invoice_due}'     => __( 'invoice due date', 'neurohr-members' ),
			'{payment_details}' => __( 'bank details from Business info', 'neurohr-members' ),
			'{plan_line}'       => __( '"Your Pro plan is active until …" (paid plan invoices)', 'neurohr-members' ),
			'{renewal_text}'    => __( 'renewal invoice text, or the renew link', 'neurohr-members' ),
			'{inviter}'         => __( 'who sent the invitation', 'neurohr-members' ),
			'{invite_url}'      => __( 'invitation link', 'neurohr-members' ),
			'{password_url}'    => __( '"choose your password" link', 'neurohr-members' ),
			'{verify_url}'      => __( 'email confirmation link', 'neurohr-members' ),
			'{amount}'          => __( 'amount to be charged (auto-renew)', 'neurohr-members' ),
			'{card}'            => __( 'saved card, e.g. Visa •••• 4242 (04/28)', 'neurohr-members' ),
			'{renew_date}'      => __( 'auto-renew date', 'neurohr-members' ),
			'{retry_date}'      => __( 'date of the second try', 'neurohr-members' ),
			'{card_url}'        => __( 'link to update the card', 'neurohr-members' ),
			'{reset_url}'       => __( 'password reset link', 'neurohr-members' ),
			'{new_email}'       => __( 'new login email', 'neurohr-members' ),
			'{site}'            => __( 'site name', 'neurohr-members' ),
			'{login_url}'       => __( 'login page', 'neurohr-members' ),
			'{account_url}'     => __( 'My account page', 'neurohr-members' ),
			'{renew_url}'       => __( 'renewal / pricing link', 'neurohr-members' ),
			'{fields}'          => __( 'registration answers (admin emails)', 'neurohr-members' ),
			'{pay_url}'         => __( 'link to pay the invoice online', 'neurohr-members' ),
			'{method}'          => __( 'how they paid (payment emails)', 'neurohr-members' ),
			'{paid_amount}'     => __( 'amount they paid (payment emails)', 'neurohr-members' ),
			'{paid_at}'         => __( 'date and time they paid (payment emails)', 'neurohr-members' ),
			'{reference}'       => __( 'bank reference (payment emails)', 'neurohr-members' ),
			'{reason}'          => __( 'why a payment was not confirmed', 'neurohr-members' ),
		);
	}

	/** Current template: on (bool), subj, body, customised (bool). */
	public static function template( $key ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $key ] ) ) {
			return null;
		}
		$subj = NHM_Settings::stored( 'email_' . $key . '_subj' );
		$body = NHM_Settings::stored( 'email_' . $key . '_body' );
		$on   = NHM_Settings::stored( 'email_' . $key . '_on' );
		return array(
			'on'         => null === $on ? true : (bool) $on,
			'subj'       => null === $subj ? $reg[ $key ]['subj'] : (string) $subj,
			'body'       => null === $body ? $reg[ $key ]['body'] : (string) $body,
			'customised' => null !== $subj || null !== $body,
		);
	}

	public static function reset( $key ) {
		NHM_Settings::forget( array( 'email_' . $key . '_subj', 'email_' . $key . '_body' ) );
	}

	public static function admin_addresses() {
		$list = array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) NHM_Settings::get( 'admin_emails' ) ) ), 'is_email' );
		return $list ? array_values( $list ) : array( get_option( 'admin_email' ) );
	}

	private static function base_vars() {
		return array(
			'{site}'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{login_url}'       => nhm_login_url(),
			'{account_url}'     => nhm_account_url() ? nhm_account_url() : home_url( '/' ),
			'{renew_url}'       => NHM_Settings::url( 'renew_url', NHM_Levels::upgrade_url() ),
			'{base_level}'      => NHM_Levels::name( NHM_Levels::base_id() ),
			'{invite_days}'     => (string) max( 1, (int) NHM_Settings::get( 'invite_days' ) ),
			'{for_company}'     => '',
			'{company}'         => '',
			'{payment_details}' => self::payment_details(),
			'{renewal_text}'    => '',
			'{plan_line}'       => '',
			'{until}'           => '',
			'{fields}'          => '',
		);
	}

	public static function payment_details() {
		$s = NHM_Settings::all();
		if ( ! $s['bank_account_no'] ) {
			return '';
		}
		$lines = array( 'Payment details:' );
		if ( $s['bank_name'] ) {
			$lines[] = 'Bank: ' . $s['bank_name'];
		}
		if ( $s['bank_account_name'] ) {
			$lines[] = 'Account name: ' . $s['bank_account_name'];
		}
		$lines[] = 'Account no.: ' . $s['bank_account_no'];
		$lines[] = 'Please use the invoice number as the payment reference.';
		return implode( "\n", $lines );
	}

	private static function user_vars( $user ) {
		return array(
			'{name}'      => $user->display_name ? $user->display_name : $user->user_login,
			'{email}'     => $user->user_email,
			'{admin_url}' => admin_url( 'user-edit.php?user_id=' . (int) $user->ID . '#nhm' ),
		);
	}

	public static function format_date( $date ) {
		return $date ? wp_date( get_option( 'date_format', 'j F Y' ), strtotime( $date . ' 12:00:00' ) ) : '';
	}

	/**
	 * Subject, HTML and headers for email $key, or null when it is switched off.
	 */
	public static function build( $key, array $vars ) {
		$tpl = self::template( $key );
		if ( ! $tpl || ! $tpl['on'] ) {
			return null;
		}
		$vars    = array_merge( self::base_vars(), $vars );
		$subject = strtr( $tpl['subj'], $vars );
		$body    = preg_replace( "/\n{3,}/", "\n\n", strtr( $tpl['body'], $vars ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = sanitize_email( (string) NHM_Settings::get( 'mail_from_email' ) );
		if ( $from ) {
			$name      = str_replace( array( "\r", "\n", '"' ), '', (string) NHM_Settings::get( 'mail_from_name' ) );
			$headers[] = 'From: ' . ( $name ? '"' . $name . '" ' : '' ) . '<' . $from . '>';
		}
		return array( 'subject' => wp_specialchars_decode( $subject, ENT_QUOTES ), 'html' => self::html( $body ), 'headers' => $headers );
	}

	/**
	 * Send email $key. Admin notices go to the admin addresses ($to is ignored for them).
	 *
	 * @param string[] $attachments File paths.
	 */
	public static function send( $key, $to, array $vars, array $attachments = array() ) {
		$built = self::build( $key, $vars );
		if ( ! $built ) {
			return false;
		}
		$reg = self::registry();
		$to  = 'admin' === $reg[ $key ]['group'] ? implode( ',', self::admin_addresses() ) : $to;
		if ( ! $to ) {
			return false;
		}
		return wp_mail( $to, $built['subject'], $built['html'], $built['headers'], $attachments );
	}

	/** "Choose your password" link on your own page. */
	public static function password_url( $user, $key ) {
		$page = NHM_Settings::page_url( 'lost' ) ? NHM_Settings::page_url( 'lost' ) : NHM_Settings::page_url( 'login' );
		return $page ? add_query_arg( array( 'action' => 'rp', 'key' => $key, 'login' => rawurlencode( $user->user_login ) ), $page ) : network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}

	/** Plain text → simple branded HTML. Links become clickable. */
	public static function html( $text ) {
		$brand = NHM_Settings::brand();
		$body  = make_clickable( wpautop( esc_html( $text ) ) );
		$body  = str_replace( '<a ', '<a style="color:' . esc_attr( $brand ) . '" ', $body );
		$site  = esc_html( get_bloginfo( 'name' ) );
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f6f8;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#1d2330;">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e7e9ee;">'
			. '<div style="height:6px;background:' . esc_attr( $brand ) . ';"></div>'
			. '<div style="padding:28px 32px;font-size:15px;line-height:1.6;">' . $body . '</div>'
			. '</div><p style="text-align:center;color:#8b909a;font-size:12px;margin:16px 0 0;">' . $site . '</p></body></html>';
	}

	/** Registration answers as text lines (admin emails). */
	public static function fields_text( $user_id ) {
		$lines = array();
		foreach ( NHM_Fields::all() as $f ) {
			$v = NHM_Fields::text( $user_id, $f );
			if ( '' !== $v ) {
				$lines[] = $f['label'] . ': ' . $v;
			}
		}
		return implode( "\n", $lines );
	}

	/* ---------- The emails ---------- */

	public static function welcome( $user_id, $level_id = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$m = NHM_Members::membership( $user_id );
		self::send( 'welcome', NHM_Members::contact_email( $user ), array_merge( self::user_vars( $user ), array( '{level}' => $m['name'] ) ) );
	}

	/** Admin notice about a new account (with the registration answers). */
	public static function admin_signup( $user_id, $level_id = 0 ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$m = NHM_Members::membership( $user_id );
			self::send( 'admin_signup', '', array_merge( self::user_vars( $user ), array( '{level}' => NHM_Levels::name( $level_id ? $level_id : $m['level'] ), '{fields}' => self::fields_text( $user_id ) ) ) );
		}
	}

	/** New account made by you / the import: link to choose a password. */
	public static function account_created( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$url  = self::password_url( $user, $key );
		$m    = NHM_Members::membership( $user_id );
		return self::send( 'account', NHM_Members::contact_email( $user ), array_merge( self::user_vars( $user ), array( '{level}' => $m['name'], '{password_url}' => $url ) ) );
	}

	public static function invite( $company, $email, $token, $inviter_id ) {
		if ( ! $company ) {
			return;
		}
		$inviter = $inviter_id ? get_userdata( $inviter_id ) : null;
		self::send(
			'invite',
			$email,
			array(
				'{name}'       => $email,
				'{email}'      => $email,
				'{company}'    => $company['name'],
				'{level}'      => NHM_Levels::name( $company['level_id'] ),
				'{expiry}'     => self::format_date( $company['expiry_date'] ),
				'{inviter}'    => $inviter ? $inviter->display_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{invite_url}' => NHM_Companies::invite_url( $token ),
			)
		);
	}

	private static function company_vars( $company ) {
		return array(
			'{company}'           => $company['name'],
			'{level}'             => NHM_Levels::name( $company['level_id'] ),
			'{expiry}'            => self::format_date( $company['expiry_date'] ),
			'{seats}'             => NHM_Companies::seats_used( $company['id'] ) . ' / ' . $company['seats'],
			'{company_admin_url}' => NHM_Companies::edit_url( $company['id'] ),
			/* translators: %s: date */
			'{until}'             => $company['expiry_date'] ? sprintf( __( ' until %s', 'neurohr-members' ), self::format_date( $company['expiry_date'] ) ) : '',
		);
	}

	/** Someone joined a company: email to them and to the admin. */
	public static function joined( $company_id, $user_id ) {
		$company = NHM_Companies::get( $company_id );
		$user    = get_userdata( $user_id );
		if ( ! $company || ! $user ) {
			return;
		}
		$vars = array_merge( self::user_vars( $user ), self::company_vars( $company ) );
		self::send( 'joined', NHM_Members::contact_email( $user ), $vars );
		self::send( 'admin_joined', '', $vars );
	}

	public static function left( $company_id, $user_id ) {
		$company = NHM_Companies::get( $company_id );
		$user    = get_userdata( $user_id );
		if ( $company && $user ) {
			self::send( 'admin_left', '', array_merge( self::user_vars( $user ), self::company_vars( $company ) ) );
		}
	}

	/** Placeholders for an invoice (for other classes). */
	public static function invoice_placeholders( $inv ) {
		return self::invoice_vars( $inv );
	}

	private static function invoice_vars( $inv ) {
		$name = ! empty( $inv['bill_to']['attention'] ) ? $inv['bill_to']['attention'] : ( isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' );
		$user = $inv['user_id'] ? get_userdata( $inv['user_id'] ) : null;
		$vars = array(
			'{name}'              => $user ? $user->display_name : $name,
			'{email}'             => NHM_Invoices::email_to( $inv ),
			'{invoice_number}'    => $inv['number'],
			'{invoice_total}'     => NHM_Settings::money( $inv['total'] ),
			'{invoice_due}'       => self::format_date( $inv['due_date'] ),
			'{invoice_admin_url}' => NHM_Invoices::edit_url( $inv['id'] ),
			'{level}'             => $inv['level_id'] ? NHM_Levels::name( $inv['level_id'] ) : '',
			'{admin_url}'         => NHM_Invoices::edit_url( $inv['id'] ),
			'{pay_url}'           => class_exists( 'NHM_Payments' ) ? NHM_Payments::pay_url( $inv ) : '',
		);
		if ( $inv['level_id'] && $inv['granted'] ) {
			if ( 'company' === $inv['grant_to'] ) {
				$c   = NHM_Companies::get( $inv['company_id'] );
				$end = $c ? $c['expiry_date'] : '';
			} else {
				$m   = NHM_Members::membership( $inv['user_id'] );
				$end = $m['personal'] ? $m['personal']['expiry'] : '';
			}
			$vars['{plan_line}'] = $end
				/* translators: 1: level, 2: date */
				? sprintf( __( 'Your %1$s plan is active until %2$s.', 'neurohr-members' ), NHM_Levels::name( $inv['level_id'] ), self::format_date( $end ) )
				/* translators: %s: level */
				: sprintf( __( 'Your %s plan is active.', 'neurohr-members' ), NHM_Levels::name( $inv['level_id'] ) );
		}
		return $vars;
	}

	/** Invoice emails with the PDF attached: application, invoice, payment. */
	public static function invoice_email( $key, $inv, array $extra = array(), $admin_copy = true ) {
		if ( ! $inv ) {
			return false;
		}
		$to   = NHM_Invoices::email_to( $inv );
		$file = NHM_PDF::invoice_file( $inv );
		$att  = is_wp_error( $file ) ? array() : array( $file );
		$ok   = self::send( $key, $to, array_merge( self::invoice_vars( $inv ), $extra ), $att );
		if ( 'application' === $key && $admin_copy ) {
			self::send( 'admin_application', '', array_merge( self::invoice_vars( $inv ), $extra ) );
		}
		NHM_PDF::cleanup( is_wp_error( $file ) ? '' : $file );
		return $ok;
	}

	/** Reminder or "ended" email for a personal plan (+ the admin copy). */
	public static function user_plan( $key, $user_id, $level_id, $expiry, $renewal = null ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$vars = array_merge(
			self::user_vars( $user ),
			array(
				'{level}'        => NHM_Levels::name( $level_id ),
				'{expiry}'       => self::format_date( $expiry ),
				'{days}'         => (string) max( 0, (int) NHM_Members::days_left( $expiry ) ),
				'{renewal_text}' => self::renewal_text( $renewal ),
			)
		);
		$att = self::renewal_attachment( $renewal );
		$ok  = self::send( $key, NHM_Members::contact_email( $user ), $vars, $att );
		self::send( 'expired' === $key ? 'admin_expired' : 'admin_reminder', '', $vars );
		NHM_PDF::cleanup( $att ? $att[0] : '' );
		return $ok;
	}

	/** Reminder or "ended" email for a company plan, to the company owner (+ the admin copy). */
	public static function company_plan( $key, $company, $renewal = null ) {
		$owner = $company['owner_id'] ? get_userdata( $company['owner_id'] ) : null;
		$vars  = array_merge(
			self::company_vars( $company ),
			array(
				'{days}'         => (string) max( 0, (int) NHM_Members::days_left( $company['expiry_date'] ) ),
				/* translators: %s: company name */
				'{for_company}'  => sprintf( __( ' for %s', 'neurohr-members' ), $company['name'] ),
				'{renewal_text}' => self::renewal_text( $renewal ),
				'{name}'         => $owner ? $owner->display_name : $company['name'],
				'{email}'        => $owner ? $owner->user_email : NHM_Companies::billing_email( $company ),
				'{admin_url}'    => NHM_Companies::edit_url( $company['id'] ),
			)
		);
		$to  = $owner ? NHM_Members::contact_email( $owner ) : NHM_Companies::billing_email( $company );
		$att = self::renewal_attachment( $renewal );
		$ok  = $to ? self::send( $key, $to, $vars, $att ) : false;
		self::send( 'expired' === $key ? 'admin_expired' : 'admin_reminder', '', $vars );
		NHM_PDF::cleanup( $att ? $att[0] : '' );
		return $ok;
	}

	private static function renewal_text( $inv ) {
		if ( $inv ) {
			/* translators: 1: invoice number, 2: total */
			return sprintf( __( 'Your renewal invoice %1$s for %2$s is attached. The plan continues as soon as it is paid.', 'neurohr-members' ), $inv['number'], NHM_Settings::money( $inv['total'] ) ) . "\n\n" . self::payment_details();
		}
		/* translators: %s: link */
		return sprintf( __( 'To renew, reply to this email or visit %s.', 'neurohr-members' ), NHM_Settings::url( 'renew_url', NHM_Levels::upgrade_url() ) );
	}

	private static function renewal_attachment( $inv ) {
		if ( ! $inv ) {
			return array();
		}
		$file = NHM_PDF::invoice_file( $inv );
		return is_wp_error( $file ) ? array() : array( $file );
	}
}
