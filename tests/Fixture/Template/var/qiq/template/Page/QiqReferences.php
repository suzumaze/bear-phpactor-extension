{{ setLayout('layout/base') }}
{{= render('/partial/structuredData/webSite') }}
{{ extends('layout/parent') }}
{{= $this->render('partial/card') }}
{{= render($dynamic) }}
{{ /* render('missing/commented') */ }}
<?php $this->render('partial/native') ?>
