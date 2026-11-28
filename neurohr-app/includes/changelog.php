<?php
/**
 * Version history of NeuroHR App.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'0.1.2' => array(
		'date'  => '28 Nov 2026',
		'items' => array(
			'Article pages: a thin orange line under the header shows how much has been read.',
			'Template bar: file type, and Download (or See Template with a lock for members below its plan). Needs Knowledge Hub 0.16.',
			'News lists get the app header with a back button.',
		),
	),
	'0.1.1' => array(
		'date'  => '28 Nov 2026',
		'items' => array(
			'Tables on phones: the first column no longer stays fixed; the whole table scrolls sideways, so small screens see more.',
		),
	),
	'0.1.0' => array(
		'date'  => '27 Nov 2026',
		'items' => array(
			'New plugin: the phone app moved here from NeuroHR Members 0.5 (bottom bar, search screen, More sheet, app home, install as an app). Settings are taken over.',
			'Bottom bar as a floating capsule; the open tab is a dark pill.',
			'Knowledge Hub pages on phones: header with back button, section chips, newest item, two-column topic cards, article header with Save and Share.',
			'Pages never get wider than the phone; tables scroll sideways on their own with the first column fixed.',
			'Faster page changes: instant tap feedback, loading on touch and the bottom-bar pages in the background (Chrome / Android), back button uses the browser history, fade between pages.',
		),
	),
);
