<?php
include("urlisp.php");

$env = standard_env();
$symbs = $env->bound();
$symb_lst = '';
foreach ($symbs as $s)
  $symb_lst .= "<li>{$s}</li>";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $ctx = array('repltext' => '', 'symbol_lst' => $symb_lst);
  echo include 'template.php';
} else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = $_POST['code'];
  $l = LexSexpr($code);
  $ast = parse_sexpr($l);

  $result = '';
  if ($ast instanceof SysError) {
    $result = sprintf("%s\n\n=> %s\n", $code, $val);
  }
  else {
    // Just want the success sexpr we just parsed, not any trailing
    // junk
    $code = join('', array_slice($l->input, 0, $l->start));
    $val = $ast->evaluate(standard_env());
    $result = sprintf("%s\n\n=> %s\n", $code, $val);
  }

  $ctx = array('repltext' => $result, 'symbol_lst' => $symb_lst);
  echo include 'template.php';
}
?>
