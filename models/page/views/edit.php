<?php

$FORM->useInputStatus();
$FORM->text('title','Title:');
$FORM->textarea('body', NULL, array(
	'style' => 'width: 95%; height: 24em;',
	'class' => implode(' ',$H('rte_classname')),
));