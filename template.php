<?php
return <<<EOS
<!DOCTYPE html>
<html>
<head>
<style>
html {
background-color: grey;
}

body {
max-width: 60em;
margin: 0 auto;
background-color: white;
}

html, body {
height: 100%;
}

#content {
clear: both;
float: left;
width: 100%;
margin-top: 0;
padding-top: 0;
border-top: 0;
margin-bottom: 0;
border-bottom: 0;
padding-bottom: 0;
background-color: white;
}

#content article {
float: left;
}

#content aside {
display: block;
float: right;
background-color: lightgrey;
padding-left: 10px;
padding-right: 10px;
}

.error {
foreground-color: red;
}
</style>
</head>
<body>
<div id="content">
  <article id="mainblock">
    <section id="repl">
      <h1>REPL</h1>
      <p>Enter an UrLisp form and hit "Evaluate" to see the evaluated result:</p>
      <form method="post" action="/site/repl.php">
        <textarea id="code_input" name="code" placeholder="Enter an UrLisp expression here">{$ctx['repltext']}</textarea><br />
        <input type="submit" value="Evaluate" />
      </form>
    </section>
    <section id="examples">
      <hgroup>
        <h2>Example Forms</h2>
        <h3>Fibonacci Numbers</h3>
      </hgroup>
      <p><pre><code>((label fib
  (lambda (a)
    (cond
      ((eq 0 a) 0)
      ((eq 1 a) 1)
      ('t (plus (fib (plus a -2)) (fib (plus a -1))))))) 3)</code></pre></p>
      <h3>Reverse Polish Macro</h3>
      <p><pre><code>((macro (a b) (b a)) 'foo atom)</code></pre></p>
      <h3>List Length</h3>
      <p><pre><code>((label count 
  (lambda (lst)
    (cond
      ((nullp lst) 0)
      ('t (plus 1
                (count (cdr lst))))))) '(a a a a))
        </code></pre></p>
    </section>
  </article>
<aside>
  <section>
    <h1>Bound Symbols</h1>
    <ul>{$ctx['symbol_lst']}</ul>
  </section>
  <section>
    <h1>Source Code</h1>
    <a href="viewsource/urlisp/">Use the source, Luke...</a>
  </section>
</aside>
</body>
</html>
EOS;
?>
