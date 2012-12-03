<?php
class ItemType
{
  const EOF = 1;
  const Atom = 2;
  const LeftParen = 6;
  const RightParen = 7;
  const Error = 8;
  const Quote = 9;
  const Whitespace = 10;
  const Integer = 11;
}

class Item
{
  public $type;
  public $val;

  public function __construct($type, $val) {
    $this->type = $type;
    $this->val = $val;
  }

  public function __toString() {
    if ($this->type === ItemType::EOF)
      return "EOF";
    return join('', $this->val);
  }
}

class Lexer
{
  public $name;
  public $input;
  public $start = 0;
  public $pos = 0;
  public $items = array();
  public $width = 1;
  public $state;

  public function next_item() {
    while ($this->state !== NULL) {
      if (count($this->items) > 0)
        return array_shift($this->items);
      $func = $this->state;
      $this->state = $func($this);
    }
    // State was nil, return the error item if possible
    if (count($this->items) > 0)
      return $this->items[0];
    return NULL;
  }

  public function emit($type) {
    array_push($this->items,
               new Item($type,
                        array_slice($this->input,
                                    $this->start, $this->pos-$this->start)));
    $this->start = $this->pos;
  }

  public function next() {
    if ($this->pos >= count($this->input)) {
      $this->width = 0;
      return EOF;
    }
    $c = $this->input[$this->pos];
    $this->pos += $this->width;
    return $c;
  }

  public function backup() {
    $this->pos -= $this->width;
  }

  public function ignore() {
    $this->start = $this->pos;
  }

  public function peek() {
    $c = $this->next();
    $this->backup();
    return $c;
  }

  public function accept($chars) {
    if (strstr($chars, $this->next()))
      return True;
    $this->backup();
    return False;
  }

  public function accept_run($chars) {
    while (strstr($chars, $this->next())) {}
    $this->backup();
  }

  public function errorf($format) {
    $args = func_get_args();
    array_shift($args);
    array_push($this->items, new Item(ItemType::Error, vsprintf($format, $args)));
    return NULL;
  }
}

function begins($arr, $start, $match) {
  $leadStr = join('', array_slice($arr, $start, strlen($match)));
  return ($leadStr === $match);
}

define("WHITESPACE", " \f\n\r\t\v");
define("LPAREN", "(");
define("RPAREN", ")");
define("QUOTE", "'");
define("ATOMTEXT", join('', range('a', 'z')) . join('', range('A', 'Z')));
define("DIGIT", join('', range('0', '9')));
define("EOF", -1);

function LexSexpr($input) {
  $l = new Lexer();
  $l->name = "URLisp Lexer";
  $l->input = str_split($input);
  $l->state = 'LexExpr';
  return $l;
}

function LexExpr($l) {
  while(TRUE) {
    $c = $l->next();
    if ($c === LPAREN)
      return 'LexLParen';
    if ($c === RPAREN)
      return 'LexRParen';
    if ($c === '-' or strstr(DIGIT, $c)) {
      $l->backup();
      return 'LexNumber';
    }
    if (strstr(ATOMTEXT, $c)) {
      $l->backup();
      return 'LexAtom';
    }
    if ($c === "'") {
      return 'LexQuote';
    }
    if (strstr(WHITESPACE, $c)) {
      $l->ignore();
      continue;
    }
    if ($c === EOF)
      break;
    return $l->errorf("Unexpected input '%s' while lexing expression", $c);
  }
  // Successful EOF
  $l->emit(ItemType::EOF);
  return NULL;
}

function LexLParen($l) {
  $l->emit(ItemType::LeftParen);
  return 'LexExpr';
}

function LexRParen($l) {
  $l->emit(ItemType::RightParen);
  return 'LexExpr';
}

function LexNumber($l) {
  // Accept a leading minus
  $l->accept("-");
  $l->accept_run(DIGIT);
  $c = $l->peek();
  // No alphas or quote after a number (atoms are whitespace-separated)
  if (strstr(ATOMTEXT, $c) or $c === "'")
    return $l->errorf("Bad input '%s' while lexing integer", $c);
  $l->emit(ItemType::Integer);
  return 'LexExpr';
}

function LexAtom($l) {
  $l->accept_run(ATOMTEXT);
  // No numerics or quotes after an atom
  $c = $l->peek();
  if (strstr(DIGIT, $c) or $c === '-' or $c === "'")
    return $l->errorf("Bad input '%s' while lexing atom", $c);
  $l->emit(ItemType::Atom);
  return 'LexExpr';
}

function LexQuote($l) {
  $l->emit(ItemType::Quote);
  return 'LexExpr';
}

// For type checking purposes
class ASTItem {}

class Atom extends ASTItem
{
  public $val;

  public function __construct($val) {
    $this->val = $val;
  }

  public function __toString() {
    return (string)$this->val;
  }

  public function evaluate($env) {
    if (!isset($env[$this->val]))
      return new LispError($this, "Symbol is not bound");
    return $env[$this->val];
  }
}

class LispList extends ASTItem implements ArrayAccess
{
  public $items;

  public function __construct() {
    $this->items = func_get_args();
  }

  public function __toString() {
    if (count($this->items) == 0)
      return "()";

    $first = array_shift($this->items);
    $str = "(" . $first;
    foreach ($this->items as $i) {
      $str .= " " . $i;
    }
    $str .= ")";
    array_unshift($this->items, $first);
    return $str;
  }

  public function evaluate($env) {
    $f = $this[0]->evaluate($env);
    if ($f instanceof LispError)
      return $f;
    if (!($f instanceof Thunk))
      return new LispError($this, sprintf("%s is not a callable object", $f));
    $argl = array_slice($this->items, 1);
    $args = new LispList();
    $args->items = $argl;

    $rv = $f->call($args, $env);
    return $rv;
  }

  public function offsetGet($name) {
    return $this->items[$name];
  }

  public function offsetSet($name, $val) {
    $this->items[$name] = $val;
  }

  public function offsetUnset($name) {
    unset($this->items[$name]);
  }

  public function offsetExists($name) {
    return isset($this->items[$name]);
  }
}

class Integer extends Atom
{
  public $val;

  public function __construct($val) {
    $this->val = intval($val);
  }

  public function evaluate() {
    return $this;
  }
}

// For type checking
class SysError {}

class ParseError extends SysError
{
  public function __construct($msg) {
    $this->msg = $msg;
  }

  public function __toString() {
    return sprintf("Parse Error: %s", $this->msg);
  }
}

function push_maybe_quoted(&$stack, $item) {
  if (count($stack) > 0 and is_quote($stack[count($stack)-1])) {
    // Remove the quote item.
    array_pop($stack);

    // Replace it with a quote form.
    $q = new Atom("quote");
    $l = new LispList($q, $item);
    array_push($stack, $l);
  } else {
    array_push($stack, $item);
  }
}

function build_list($stack) {
  $l = new LispList();
  while (True) {
    $i = array_pop($stack);
    if ($i === NULL)
      return new ParseError("Internal error: NULL element on parse stack or empty stack");

    if ($i instanceof Item) {
      if ($i->type === ItemType::LeftParen) {
        push_maybe_quoted($stack, $l);
        return $stack;
      } else {
        return new ParseError("Internal error: found a lexical token that wasn't an open paren while building a list");
      }
    }
    if ($i instanceof ASTItem)
      array_unshift($l->items, $i);
  }
}

function is_quote($i) {
  if ($i instanceof Item and $i->type === ItemType::Quote)
    return True;
  return False;
}

// Parse one sexpr from the supplied lexer
function parse_sexpr($l) {
  $stack = array();

  while (count($stack) == 0 or !($stack[0] instanceof ASTItem)) {
    $i = $l->next_item();
    if ($i->type === ItemType::EOF)
      return ParseError("S-expression interrupted by EOF!");
    if ($i->type === ItemType::Whitespace)
      continue;
    if ($i->type === ItemType::Atom) {
      push_maybe_quoted($stack, new Atom(join('', $i->val)));
      continue;
    }
    if ($i->type === ItemType::Integer) {
      push_maybe_quoted($stack, new Integer(join('', $i->val)));
      continue;
    }
    if ($i->type === ItemType::RightParen) {
      $stack = build_list($stack);
      if ($stack instanceof ParseError)
        return $stack;
      continue;
    }
    if ($i->type === ItemType::LeftParen or $i->type === ItemType::Quote) {
      array_push($stack, $i);
      continue;
    }
    if ($i->type === ItemType::Error) {
      return new ParseError(sprintf("Lex error at character %d: %s", $l->pos, $i->val));
    }
    return new ParseError(sprintf("Unrecognized lexical token of type %d ending at character %d", $i->type, $l->start));
  }

  return $stack[0];
}

/* Evaluator */

class LispError extends SysError
{
  public function __construct($obj, $msg) {
    $this->msg = $msg;
    $this->obj = $obj;
  }

  public function __toString() {
    return sprintf("Lisp Error: %s: %s", $this->obj, $this->msg);
  }
}

// An environment stack
class Environment implements ArrayAccess
{
  public $env_lst = array();

  public function __construct($base=NULL) {
    if ($base !== NULL)
      array_unshift($this->env_lst, $base);
    array_unshift($this->env_lst, array());
  }

  public function bound() {
    $vars = array_keys($this->env_lst[0]);

    if (count($this->env_lst) > 1)
      return array_merge($vars, $this->env_lst[1]->bound());
    return $vars;
  }

  public function offsetGet($name) {
    foreach ($this->env_lst as $env) {
      if (isset($env[$name]))
        return $env[$name];
    }
    return NULL;
  }

  public function offsetSet($name, $value) {
    $this->env_lst[0][$name] = $value;
  }

  public function offsetUnset($name) {
    unset($this->env_lst[0][$name]);
  }

  public function offsetExists($name) {
    foreach ($this->env_lst as $env) {
      if (isset($env[$name]))
        return True;
    }
    return False;
  }
}

abstract class Thunk
{
  public $params;
  public $body;

  public function __construct($params, $body) {
    $this->params = $params;
    $this->body = $body;
  }

  public function evaluate($env) {
    return $this;
  }

  public function eval_body($env) {
    return $this->body->evaluate($env);
  }

  abstract public function bind_args($args, $params, $env);

  public function call($args, $env) {
    if (count($args->items) < count($this->params->items))
      return new LispError($this, sprintf("Too few arguments passed for parameter list %s", $this->params));
    if (count($args->items) > count($this->params->items))
      return new LispError($this, sprintf("Too many arguments passed for parameter list %s", $this->params));

    $e = $this->bind_args($args, $this->params, $env);
    if ($e instanceof LispError)
      return $e;
    return $this->eval_body($e);

    for ($i=0; $i<count($this->params->items); $i++) {
      $p = $this->params[$i];
      if (!($p instanceof Atom))
        return new LispError($this, sprintf("Parameter '%s' is not an atom", $p));
      $rv = $this->bind($args[$i], $p, $e);
      if ($rv instanceof LispError)
        return $rv;
    }
    return $this->eval_body($e);
  }
}

class LispFunction extends Thunk
{
  public function bind_args($args, $params, $env) {
    $e = new Environment($env);
    for ($i=0; $i<count($params->items); $i++) {
      $p = $params[$i];
      if (!($p instanceof Atom))
        return new LispError($this, sprintf("Parameter '%s' is not an atom", $p));

      $v = $args[$i]->evaluate($env);
      if ($v instanceof LispError)
        return $v;
      $e[$params[$i]->val] = $v;
    }
    return $e;
  }

  public function __toString() {
    return "#<function>";
  }
}

class MacroLike extends Thunk
{
  public function bind_args($args, $params, $env) {
    $e = new Environment($env);
    for ($i=0; $i<count($params->items); $i++)
      $e[$params[$i]->val] = $args[$i];
    return $e;
  }
}

// FExprs
class LispMacro extends MacroLike {
  public function __toString() {
    return "#<macro>";
  }
}

// class for special macro-like builtins (e.g. cond)
class SpecialForm extends MacroLike {}

class LabelledFunction extends LispFunction
{
  public function __construct($label, $params, $body) {
    parent::__construct($params, $body);
    $this->label = $label;
  }

  public function call($args, $env) {
    $e = new Environment($env);
    $e[$this->label->val] = $this;
    return parent::call($args, $e);
  }

  public function __toString() {
    return sprintf("#<labelled_function %s>", $this->label);
  }
}

/* Special function */
class CondForm extends SpecialForm
{
  // override call() to support variable arg list
  public function call($args, $env) {
    foreach ($args->items as $form) {
      $pred = $form[0]->evaluate($env);
      if ($pred instanceof SysError)
        return $pred;
      if ($pred instanceof Atom and $pred->val === 't') {
        return $form[1]->evaluate($env);
      }
    }
    return LispError($this, "No conditional forms supplied");
  }

  public function __toString() {
    return "#<special form 'cond'>";
  }
}

class QuoteForm extends SpecialForm
{
  public function eval_body($env) {
    return $env[$this->params[0]->val];
  }
}

class CarFunc extends LispFunction
{
  public function eval_body($env) {
    $l = $env[$this->params[0]->val];
    return $l->items[0];
  }

  public function __toString() {
    return "#<builtin function 'car'>";
  }
}

class CdrFunc extends LispFunction
{
  public function eval_body($env) {
    $l = $env[$this->params[0]->val];
    $newl = new LispList();
    $newl->items = array_slice($l->items, 1);
    return $newl;
  }

  public function __toString() {
    return "#<builtin function 'cdr'>";
  }
}

class AtomFunc extends LispFunction
{
  public function eval_body($env) {
    $a = $env[$this->params[0]->val];
    if ($a instanceof Atom)
      return new Atom("t");
    return new LispList();
  }

  public function __toString() {
    return "#<builtin function 'atom'>";
  }
}

class EqFunc extends LispFunction
{
  public function eval_body($env) {
    $a1 = $env[$this->params[0]->val];
    $a2 = $env[$this->params[1]->val];
    if ($a1 instanceof Atom and $a2 instanceof Atom
        and $a1->val === $a2->val)
      return new Atom('t');
    if ($a1 instanceof LispList and $a2 instanceof LispList
        and count($a1->items) == 0 and count($a2->items) == 0)
      return new Atom('t');
    return new LispList();
  }

  public function __toString() {
    return "#<builtin function 'eq'>";
  }
}

class ConsFunc extends LispFunction
{
  public function eval_body($env) {
    $newl = new LispList();
    $newl->items = $env[$this->params[1]->val]->items;

    array_unshift($newl->items, $env[$this->params[0]->val]);
    return $newl;
  }

  public function __toString() {
    return "#<builtin function 'cons'>";
  }
}

class LambdaForm extends SpecialForm
{
  public function eval_body($env) {
    $param_lst = $env[$this->params[0]->val];
    $body = $env[$this->params[1]->val];
    return new LispFunction($param_lst, $body);
  }

  public function __toString() {
    return "#<special form 'lambda'>";
  }
}

class MacroForm extends SpecialForm
{
  public function eval_body($env) {
    $params = $env[$this->params[0]->val];
    $body = $env[$this->params[1]->val];
    return new LispMacro($params, $body);
  }

  public function __toString() {
    return "#<special form 'macro'>";
  }
}

class LabelForm extends SpecialForm
{
  public function eval_body($env) {
    $label = $env[$this->params[0]->val];
    $param_lst = $env[$this->params[1]->val]->items[1];
    $body = $env[$this->params[1]->val]->items[2];
    return new LabelledFunction($label, $param_lst, $body);
  }

  public function __toString() {
    return "#<special form 'label'>";
  }
}

class PlusFunc extends LispFunction
{
  public function eval_body($env) {
    $a = $env[$this->params[0]->val]->val;
    $b = $env[$this->params[1]->val]->val;
    return new Integer(sprintf("%d", $a + $b));
  }

  public function __toString() {
    return "#<builtin function 'plus'>";
  }
}

class MultFunc extends LispFunction
{
  public function eval_body($env) {
    $a = $env[$this->params[0]->val]->val;
    $b = $env[$this->params[1]->val]->val;
    return new Integer(sprintf("%d", $a * $b));
  }

  public function __toString() {
    return "#<builtin function 'mult'>";
  }
}

class LEFunc extends LispFunction
{
  public function eval_body($env) {
    $a = $env[$this->params[0]->val]->val;
    $b = $env[$this->params[1]->val]->val;
    if ($a <= $b)
      return new Atom("t");
    return new LispList();
  }

  public function __toString() {
    return "#<builtin function 'le'>";
  }
}

function atom_list() {
  $l = new LispList();
  $funargs = func_get_args();
  foreach ($funargs as $arg) {
    array_push($l->items, new Atom($arg));
  }
  return $l;
}

function base_env() {
  $e = new Environment();
  $e['quote'] = new QuoteForm(atom_list("arg"), NULL);
  $e['eq'] = new EqFunc(atom_list("a", "b"), NULL);
  $e['car'] = new CarFunc(atom_list("arg"), NULL);
  $e['cdr'] = new CdrFunc(atom_list("arg"), NULL);
  $e['cond'] = new CondForm(NULL, NULL);
  $e['cons'] = new ConsFunc(atom_list("e", "lst"), NULL);
  $e['atom'] = new AtomFunc(atom_list("a"), NULL);
  $e['lambda'] = new LambdaForm(atom_list("param_lst", "body"), NULL);
  $e['macro'] = new MacroForm(atom_list("param_lst", "body"), NULL);
  $e['label'] = new LabelForm(atom_list("lbl", "lambdaform"), NULL);
  $e['le'] = new LEFunc(atom_list("a", "b"), NULL);
  $e['plus'] = new PlusFunc(atom_list("a", "b"), NULL);
  $e['mult'] = new MultFunc(atom_list("a", "b"), NULL);
  return $e;
}

function standard_env() {
  $defuns = array('nullp' => "(lambda (x) (eq x '()))",
                  'not' => "(lambda (x) (cond (x 't) ('t '())))",
                  'and' => "(lambda (x y)" .
                  "(cond (x (cond (y 't) ('t '())))" .
                  "      ('t '())))",
                  'append' => "(label append" .
                  "(lambda (x y)" .
                  "  (cond" .
                  "    ((null x) y)" .
                  "    ('t (cons (car x)" .
                  "              (append (cdr x) y))))))",
                  'ge' => "(lambda (x y) (le y x))",
                  'lt' => "(lambda (x y) (and (le x y) (not (ge x y))))",
                  'gt' => "(lambda (x y) (lt y x))",
                  'pair' => "(label pair " .
                  "(lambda (x y)" .
                  "  (cond" .
                  "    ((and (null x) (null y)) '())" .
                  "    ((and (not (atom x)) (not (atom y)))" .
                  "     (cons (cons (car x) (cons (car y) '()))".
                  "           (pair (cdr x) (cdr y)))))))",
                  'assoc' => "(label assoc" .
                  "(lambda (x y)" .
                  "  (cond" .
                  "    ((null y) '())" .
                  "    ((eq (car (car y)) x)".
                  "     (car (cdr (car y))))" .
                  "    ('t (assoc x (cdr y))))))",
                  'ge' => "(lambda (a b) (le b a))",
                  // The Grand Finale, an UrLisp interpreter in UrLisp
                  'evalcond' => "(label evalcond" .
                  "(lambda (lst a)" .
                  "  (cond" .
                  "    ((eval (car (car lst)) a)" .
                  "     (eval (car (cdr (car lst)))))" .
                  "    ('t (evalcond (cdr lst) a)))))",
                  'evallist' => "(label evallist" .
                  "(lambda (lst a)" .
                  "  (cond" .
                  "    ((null lst) '())" .
                  "    ('t" .
                  "     (cons (eval (car lst) a)" .
                  "                 (evallist (cdr lst) a))))))",
                  'eval' => "(label eval".
                  "(lambda (e a)" .
                  "  (cond".
                  "    ((atom e) (assoc e a))" .
                  "    ((atom (car e))" .
                  "     (cond".
                  "       ((eq (car e) 'quote) (car (cdr e)))" .
                  "       ((eq (car e) 'atom) (atom (eval (car (cdr e)) a)))".
                  "       ((eq (car e) 'eq) (eq (eval (car (cdr e)) a)" .
                  "                             (eval (car (cdr (cdr e))) a)))" .
                  "       ((eq (car e) 'car) (car (eval (car (cdr e)) a)))" .
                  "       ((eq (car e) 'cdr) (cdr (eval (car (cdr e)) a)))" .
                  "       ((eq (car e) 'cons (cons (eval (car (cdr e)) a)" .
                  "                                (eval (car (cdr (cdr e))) a))))" .
                  "       ((eq (car e) 'cond (evalcond (cdr e) a)))" .
                  "       ('t (eval (cons (assoc (car e) a)" .
                  "                       (cdr e))))))" .
                  "    ((eq (car (car e)) 'label)" .
                  "     (eval (cons (car (cdr (cdr (car e)))) (cdr e))" .
                  "           (cons (cons (car (cdr (car e))) (cons  (car e) '()))" .
                  "           a)))".
                  "    ((eq (car (car e)) 'lambda)" .
                  "     (eval (car (cdr (cdr (car e))))" .
                  "           (append (pair (car (cdr (car e)))" .
                  "                         (evallist (cdr e) a))" .
                  "                    a))))))");

  $e = new Environment(base_env());
  foreach ($defuns as $name => $form) {
    $sexpr = parse_sexpr(LexSexpr($form));
    if ($sexpr instanceof SysError)
      return $sexpr;
    $val = $sexpr->evaluate($e);
    if ($val instanceof LispError)
      return $val;
    $e[$name] = $val;
  }
  return $e;
}

$senv = standard_env();
function seval($input) {
  global $senv;
  var_dump(parse_sexpr(LexSexpr($input))->evaluate($senv));
}
?>
