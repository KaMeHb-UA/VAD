<?php define("JS_VERSION", "0.00110");

/*
  the javascript interpreter for php
  ŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻŻ
  Allows to execute PHP/EcmaScript-lookalike code in a (safe) sandbox,
  where interfaces into the hosting interpreter are possible. This may
  be useful to be embeded into CMS/Wiki engines, to provide users the
  ability to extend a site, without giving them direct and full access
  to the Web server.

  Exceptionally this is FreeWare and not PublicDomain. (Read: you
  can use it within and modify it for any other project, but replacing
  this paragraph with the GNU GPL comment is not allowed.)
  (c) 2004 WhoEver wants to. | <mario*erphesfurt·de>

  Interfaces
  ŻŻŻŻŻŻŻŻŻŻ
   · js_exec($source_code)                 // simple all-in-one
   · $output = js($source_code)            // alternative

   · js_compile($source_code)              // compile into $bc
   · jsi_register_func($js_func_name, $php_func)
   · $jsi_vars["var.name"] = "value"
   · jsi_run()                             // execute the loaded $bc


  things that are not (yet) implemented:
  -------------------------------------
  - unary operators
  - case-insensitivity for vars/funcs
  - arrays (this is however partially prepared)
  - function definitions (lambda and usual style)
  - object contexts and 'new' operator
  - local variable contexts
  - break statements may be difficult to achieve
  - string expansion with PHP $variables and backslash escaping
  - function parameters pass-by-ref
*/


// _NOTICEs should be turned off (you have been warned)
error_reporting(error_reporting() & (0xFFFF^E_NOTICE));


#-- a few settings
define("JS_PHPMODE", 0);   // not implemented
define("JS_CACHE", "/tmp/js");
define("JS_DEBUG", 0);
define("JS_FAST_CODE", !JS_DEBUG);


#-- language tokens (enable for more speed and less mem use)
if (JS_FAST_CODE) {
   define("JS_RT",	1);
   define("JS_OP_PFIX",	2);
   define("JS_FOREACH",	3);
   define("JS_OP_UNARY",	4);
   define("JS_VALUE",	5);
   define("JS_ELSE",	6);
   define("JS_FOR",	7);
   define("JS_ASSIGN",	8);
   define("JS_ERROR",	9);
   define("JS_OP_BOOL_OR",	10);
   define("JS_MATH",	11);
   define("JS_WHILE",	12);
   define("JS_SQBRCKT",	13);
   define("JS_BRACES",	14);
   define("JS_INT",	15);
   define("JS_FCALL",	16);
   define("JS_OP_BIT",	17);
   define("JS_SWITCH",	18);
   define("JS_WORD",	19);
   define("JS_CMP",	20);
   define("JS_DO",	21);
   define("JS_COMMENT",	22);
   define("JS_BOOL",	23);
   define("JS_OP_BOOL_AND",	24);
   define("JS_VERSION",	25);
   define("JS_OP_PLUS",	26);
   define("JS_PRINT",	27);
   define("JS_REAL",	28);
   define("JS_OP_MULTI",	29);
   define("JS_ELSEIF",	30);
   define("JS_COMMA",	31);
   define("JS_END",	32);
   define("JS_FUNCDEF",	33);
   define("JS_STR",	34);
   define("JS_OP_CMP",	35);
   define("JS_COND",	36);
   define("JS_CURLYBR",	37);
   define("JS_CASE",	38);
   define("JS_IF",	39);
}

#-- regular expressions to detect tokens
$types = array(
   JS_REAL	=> '\d+\.\d+',
   JS_INT	=> '\d+',
   JS_BOOL	=> '(?i:TRUE|FALSE)',
   JS_WORD	=> '\$?[_A-Za-z]+(?:\.?[_\w]+)*',
   JS_STR	=> '(?:\"[^\"]*?\"|\'[\']*?\')',
   JS_COMMENT	=> '(?:/\*.*?\*/|//[^\n]*)',
   JS_OP_CMP	=> '(?:[<>]=?|[=!]==?)',
   JS_ASSIGN	=> '(?:[-/%&|^*+:]=|=)',
   JS_OP_PFIX	=> '(?:\+\+|--)',
   JS_OP_PLUS	=> '[+-]',
   JS_OP_MULTI	=> '[*/%.]',
   JS_OP_BOOL_AND => '&&',
   JS_OP_BOOL_OR => '\|\|',
   JS_END	=> ';',
   JS_OP_BIT	=> '[&|^]',
   JS_OP_UNARY	=> '[!~]',
   JS_BRACES	=> '[()]',
   JS_SQBRCKT	=> '[\[\]]',
   JS_CURLYBR	=> '[\{\}]',
   JS_COMMA	=> ',',
   JS_ERROR     => "[^\s]",
);
$typetrans = array(
   JS_INT => JS_VALUE,
   JS_REAL => JS_VALUE,
   JS_STR => JS_VALUE,
);
$typetrans_word = array(
   "for" => JS_FOR,
   "foreach" => JS_FOREACH,
   "function" => JS_FUNCDEF,
   "while" => JS_WHILE,
   "do" => JS_DO,
   "if" => JS_IF,
   "else" => JS_ELSE,
   "elseif" => JS_ELSEIF,
   "switch" => JS_SWITCH,
   "case" => JS_CASE,
   "echo" => JS_PRINT,
   "print" => JS_PRINT,
);






#------------------------------------------------------------------------
#-- simplified interface



function js_exec($codestr, $cleanup=0)
{
    #-- parse code into global $bc
    js_compile($codestr, $cleanup);

    #-- run interpreter
    jsi_run();
    //print_r($GLOBALS["bc"]);
    if ($cleanup) { $GLOBALS["bc"] = NULL; }
}


function js_compile($codestr, $cleanup=0)
{
    #-- cut source code into lexograpic tokens
    js_lex($codestr);
    if (JS_DEBUG) {
       js_delex();
    }

    #-- parse into bytecode
    jsp_generate();
    if ($cleanup) { $GLOBALS["tn"] = NULL; }
}


function js($script=NULL) {
   global $bc;
   $r = NULL;

   #-- compile+cache or load
   if ($script) {
      $md5 = JS_CACHE."/".md5($script) . ".bc.gz";
      if (file_exists(JS_CACHE) && file_exists(JS_CACHE."/".$md5)) {
         $f = gzopen($md5, "rb");
         $bc = gzread($f, 1<<20);
         gzclose($f);
      }
      else {
         js_compile($script, "_CLEAN=1");
         $r = js();
         $f = gzopen($md5, "wb");
         fwrite($f, serialize($bc));
         gzclose($f);
      }
   }

   #-- exec, collect output
   if (!isset($r)) {
      ob_start();
      ob_implicit_flush(0);
      jsi_run();
      $r = ob_get_contents();
      ob_end_clean();
   }

   return($r);
}




#---------------------------------------------------------------------
  ##      ####### ##   ## ####### ######
  ##      ##      ##   ## ##      ##   ##
  ##      ##       ## ##  ##      ##   ##
  ##      ######    ###   ######  ######
  ##      ##       ## ##  ##      ####
  ##      ##      ##   ## ##      ## ##
  ##      ##      ##   ## ##      ##  ##
  ####### ####### ##   ## ####### ##   ##
#---------------------------------------------------------------------


# Cuts the input source text into more easily analyzeable chunks
# (tokens), each with a type flag associated.
#
function js_lex($str) {

   global $types, $tn, $typetrans, $typetrans_word;

   $tn = array();

   $str = trim($str);
   while ($str) {

      #-- split string in tokens and guess its type
      foreach ($types as $T=>$regex) {

         if (preg_match("#^$regex#", $str, $uu)) {
            $val = $uu[0];
       #echo "($T,$uu[0])\n";

            #-- no valid regex found
            if ($T==JS_ERROR) {
               jsp_err("cannot handle '".substr($str,0,10)."...'");
            }

            #-- strip found thingi away from input string
            $str = substr($str, strlen($val));

            #-- special cases to take care of in the lexer 
            switch ($T) {

               case JS_COMMENT:
                  break 2;
                  break;

               case JS_STR:
                  $val = substr($val, 1, strlen($val) - 2);
                  break;

               case JS_WORD:
                  $val = strtolower($val);
                  if ($new = $typetrans_word[$val]) {
                     $T = $new;
                     $val = NULL;
                  }
                  while ($val[0] == "$") {
                     $val = substr($val, 1);
                  }
                  break;

               case JS_BOOL:
                  $T = JS_INT;
                  $val = (strlen($val) == 4) ?1:0;
                  break;
               case JS_INT:
                  $val = (int) $val;
                  break;
               case JS_REAL:
                  $val = (double) $val;
                  break;
            }

            #-- valid language token
            if ($new = $typetrans[$T]) {
               $tn[] = array($new, $val, $T);
            }
            else {
               $tn[] = array($T, $val);
            }
            break;
         }
      }

      $str = ltrim($str);
   }
}


# prints the token streams` contents
#
function js_delex() {
   global $tn, $bc;
   foreach ($tn as $data) {
      list($T, $str) = $data;
      if (!strlen($str)) { $str = $T; }
      echo "$str";
      if (($T==JS_END) or ($T==JS_CURLYBR)) {
         echo "\n";
      }
   }
}


# prints the tokens (_DEBUG)
#
function jsp_print_tn($tn) {
   foreach ($tn as $i=>$d) {
      $t = strlen($d[0])<8 ? "\t" : "";
      echo "#$i\t$d[0]$t\t$d[1]\t$d[2]\n";
   }
}





#---------------------------------------------------------------------
  ######   #####  ######   #####  ####### ######
  ##   ## ##   ## ##   ## ##   ## ##   ## ##   ##
  ##   ## ##   ## ##   ## ##      ##      ##   ##
  ######  ####### ######   #####  #####   ######
  ##      ##   ## ####         ## ##      ####
  ##      ##   ## ## ##   ##   ## ##      ## ##
  ##      ##   ## ##  ##  ##   ## ##   ## ##  ##
  ##      ##   ## ##   ##  #####  ####### ##   ##
#---------------------------------------------------------------------


# get first entry from token stream
#
function jsp_get() {

   global $type, $val, $next, $nextval,
      $jsp_i, $tn;

   list($type, $val) = $tn[$jsp_i];
   list($next, $nextval) = $tn[$jsp_i+1];

   if (JS_DEBUG) {
      echo "@$jsp_i: t=$type,v=$val,n=$next,nv=$nextval\n";
   }

   $jsp_i++;
}


# get second entry from token stream, but as current $type
#
function jsp_getnext() {
   global $jsp_i;
   jsp_get();
   $jsp_i--;
}


# write an error message
#
function jsp_err($s) {
   echo "\nPARSER ERROR: $s\n";
}
function jsp_bug($s) {
   jsp_err("this IS A BUG in phpjs: $s");
}


# compare current token type (and subtype),
# put out an error message, if it does not match desired tags
#
function jsp_expect($t, $str=false, $caller=false) {
   global $type, $val, $next, $nextval, $jsp_i;
   if (($type != $t) || (is_array($t) && !in_array($type, $t)) || ($str) && ($val != $str)) {
      if ($str) {
         $t = $str;
         $type = $val;
      }
      jsp_err("PARSE ERROR: '$t' expected, but '$type' seen @".($jsp_i-1)
              . " by $caller");
   }
}


#-------------------------------------------------------------------------


# parse whole script
#
function jsp_generate() {

   global $bc, $tn, $jsp_i;
   $jsp_i = 0;

   #-- initial mini transformations
   if (JS_DEBUG) {
      echo "\nall parsed \$tokens:\n";
      jsp_print_tn($tn);
   }

   #-- array of expressions/commands
   $bc = array();

   #-- parse main program
   jsp_code_lines($bc["."]);

   if (JS_DEBUG) {
      echo "\ngenerated \$bytecode = ";
      print_r($bc);
   }
}




#---------------------------------------------------------------------
#-- expressions

/*
  following code uses a token look-ahead paradigm,
  where $next is examined, and (current) $type
  usually treaten as the left side argument of any
  expression
*/



# <assignment> ::= <identifier> <assign_operator> <expr>
#
function jsp_assign() {
   if(JS_DEBUG) echo "_ASSIGN\n";
   global $type, $val, $next, $nextval;

   #-- left side (varname)
   $r = array(JS_ASSIGN);
   $r[] = array(JS_WORD, $val);
   jsp_get();
   jsp_expect(JS_ASSIGN, 0, "assign");

   #-- combined assignment+operator
   $math = $val[0];
   if (($math == "=") || ($math == ":")) {
      $math = false;
   }

   #-- right side (expression)
   if ($math) {
      $r[] = array(JS_MATH, $r[1], $math, jsp_expr_start());
   } else {
      $r[] = jsp_expr_start();
   }

   return($r);
}


# <function_call> ::= <identifier> "(" (<expr> ("," <expr>)* )? ")"
#
function jsp_function_call() {
   if(JS_DEBUG) echo "_FCALL\n";
   global $type, $val, $next, $nextval;
   $r = array(JS_FCALL, $val);
   jsp_get();
   jsp_append_list($r, JS_BRACES, ")");
   jsp_get();
   jsp_expect(JS_BRACES, ")", "function_call");
   return($r);
}


# <var_or_func> ::= <idf> | <assignment> | <function_call> | <idf> (++|--)
#
function jsp_var_or_func() {
   global $type, $val, $next, $nextval;

   if(JS_DEBUG) echo "_VAR\n";
   jsp_expect(JS_WORD, 0, "var_or_func");

   #-- array
   // ...

   if (($next == JS_BRACES) && ($nextval=="(")) {
      return(jsp_function_call());
   }
   elseif ($next == JS_ASSIGN) {
      return(jsp_assign());
   }
   elseif ($next == JS_OP_PFIX) {
      $var = array(JS_WORD, $val);
      jsp_get();
      return
         array(JS_ASSIGN, $var, array(JS_MATH, $var, $val[0], 1));
   }
   else {
      return(array(JS_WORD, $val));
   }
}


# <pfix_var> ::=  (++ | --) <identifier>
# are transformed into regular "var := var (+|-) 1" interpreter stream
#
function jsp_prefix_var() {
   global $val;
   $operation = $val[0];
   jsp_get();
   $var = jsp_var_or_func();   // bad: we shouldn't get a function here at all!
   if ($var[0] != JS_WORD) {   // (except if they may return references, hmm??)
      jsp_err("complex construct where variable reference expected @$GLOBALS[jsp_i]");
   }
   return
      array(JS_ASSIGN, $var,
         array(JS_MATH, $var, $operation, 1)
      );
}


# <expr_op_unary> ::=   "~" <value>  |  "!" <value>
#
function jsp_expr_op_unary() {
   global $type, $val, $next, $nextval, $jsp_i;
   switch ($val) {
      case "~":
         return array(JS_MATH, 0, "~", jsp_expr_value());
      case "!":
         return array(JS_MATH, 0, "!", jsp_expr_value());
      default:
         jsp_bug("unary operator mistake");
   }
}


# <value> ::= "(" <expr> ")" | <var_or_func> | <constant> | <expr_op_unary>
#
function jsp_expr_value($uu=0) {
   global $type, $val, $next, $nextval, $jsp_i;

   jsp_get();
   switch ($type) {

      case JS_BRACES:
         if(JS_DEBUG) echo "_(\n";
         jsp_expect(JS_BRACES, "(", "_expr_value");
         $r = jsp_expr_start();
         jsp_get();
         jsp_expect(JS_BRACES, ")", "_expr_value");
         return($r);
         break;

      case JS_OP_PFIX:
         return jsp_prefix_var();
         break;

      case JS_OP_UNARY:
         return jsp_expr_op_unary();
         break;

      case JS_WORD:
         return jsp_var_or_func();
         break;

      default:
         if(JS_DEBUG) echo "_CONST\n";
         jsp_expect(JS_VALUE, 0, "_expr_value");
         return(array(JS_VALUE, $val));
   }
}


#-- expression grammar
#   (defines the precedence of operators)
$jsp_expr_math = array(
   JS_OP_BOOL_OR,
   JS_OP_BOOL_AND,
   JS_OP_BIT,
   JS_OP_PLUS,
   JS_OP_MULTI,
);
# <expr_multiply>  ::=  <_value> | <_value> (*|/|%) <_value>
# <expr_plusminus> ::=  <_multiply> | <_multiply> (+|-) <_multiply>
# <expr_bitop>     ::=  <_plusminus> | <_plusminus> (&|^|"|") <_plusminus>
# <expr_booland>   ::=  <_bitop> | <_bitop> ("&&") <_bitop>
# <expr_boolor>    ::=  <_booland> | <_booland> ("||") <_booland>


# ABSTRACT <expr_math> ::=  <_value> | <_value> (OPERATOR) <_value>
#
function jsp_expr_math($num=0) {
   global $type, $val, $next, $nextval, $jsp_expr_math;

   $upfunc = "jsp_expr_math";
   $OPERATOR = $jsp_expr_math[$num];
   $num++;
   if ($OPERATOR==JS_OP_MULTI) {
      $upfunc = "jsp_expr_value";
   }

   #-- get first expression
   $A = $upfunc($num);

   #-- check for (expected) operator
   if ($next == $OPERATOR) {
      $r = array(
         JS_MATH,
         $A,
      );
      while ($next == $OPERATOR) {
         jsp_get();
         $r[] = $val;   // +,- or *,/,% or &&,|| or
         $r[] = $upfunc($num);
      }
      return($r);
   }
   else {
      return($A);
   }
}


# <expr> ::= <_math> | <_math> (">=" | "<=" | "==" | ">" | "<" | "!=") <_math>
#
function jsp_expr_cmp() {
   global $type, $val, $next, $nextval, $jsp_expr_math;

   #-- get left side expression
   $A = jsp_expr_math();

   #-- check for comparision operator
   if ($next == JS_OP_CMP) {
      jsp_get();
      $r = array(
         JS_CMP,
         $A,
         $val,
      );
      $r[] = jsp_expr_math();
      return($r);
   }
   else {
      return($A);
   }
}


#   <expr> ::= <expr_plusminus>
#
function jsp_expr_start() {
   return jsp_expr_cmp();
}





#---------------------------------------------------------------------
#-- language constructs

/*
  unlike the expression code above, the following
  language construct analyzation functions don't
  have yet a filled-in $type, but in real called
  jsp_getnext() to have the values for the next
  token in $type and $val (pre-examine)

  therefore the language construct functions (except
  _block and _lines) usually start stripping the
  first token with jsp_get()
*/


# extracts a comma separated list (of expressions)
#
function jsp_append_list(&$bc, $term=JS_END, $termval=";", $comma=JS_COMMA) {
   global $type, $val, $next, $nextval;
   while (($next!=$term) && ($nextval!=$termval)) {
      $bc[] = jsp_expr_start();
      if ($next == $comma) {
         jsp_get();
      }
   }
}


# chunk a for() loop
#
function jsp_constr_for(&$bc) {

   #-- remove tokens, get list (<expr>; <expr>; <expr>)
   jsp_get();   # "for"
   jsp_get();   # "("
   jsp_expect(JS_BRACES, "(", "_constr_for0");
   $r = array();
   jsp_append_list($r, JS_BRACES, ")", JS_END);
   jsp_get();   # remove closing brace
   if (count($r) != 3) {
      jsp_err("there must be exactly three arguments in a for() loop");
   }

   #-- initial expression goes into the bc stream (before the JS_FOR entry)
   $bc[] = $r[0];
   $r[0] = JS_FOR;   # convert into bytecode stream for jsi_
   $r[3] = array();  # append code block
   jsp_block($r[3]);
   $bc[] = $r;       # output into stream
}


# if statement
#
function jsp_constr_if(&$bc) {
   global $type, $val, $next, $nextval;

   $r = array(JS_COND, JS_IF);  # if-conditional in bytecode

   #-- loop through if() and elseif() conditions and blocks
   while (($type==JS_IF) || ($next==JS_ELSEIF)) {

      #-- remove tokens
      jsp_get();   # "if" or "elseif" or "else"
      $is = $type;
      jsp_get();   # "("
      jsp_expect(JS_BRACES, "(", "_constr_if");

      #-- generate bc stream
      $r[] = jsp_expr_start();
      $r[] = array();
      jsp_get();   # ")"
      jsp_expect(JS_BRACES, ")", "_constr_if2");
      jsp_block($r[count($r)-1]);
   }
   
   #-- optional else block
   if ($type==JS_ELSE) {
      jsp_get();
      $r[] = array(JS_VALUE, 1);
      $r[] = array();
      jsp_block($r[count($r)-1]);
   }

   $bc[] = $r;
}


# while statement
#
function jsp_constr_while(&$bc) {
   global $type, $val, $next, $nextval;

   #-- remove tokens
   jsp_get();   # "while"
   jsp_get();   # "("
   jsp_expect(JS_BRACES, "(", "_constr_while");

   #-- while-conditional in bytecode
   $r = array(
      JS_COND,
      JS_WHILE,
      jsp_expr_start(),
      array()    // placeholder
   );
   jsp_get();   # ")"
   jsp_expect(JS_BRACES, ")", "_constr_while2");
   jsp_block($r[3]);

   $bc[] = $r;
}


# do statement
#
function jsp_constr_do(&$bc) {
   global $type, $val, $next, $nextval;
   #-- generate bc stream
   $r = array(
      JS_COND,
      JS_DO,
      0,        // placeholder
      array()   // placeholder
   );
   #-- remove tokens
   jsp_get();   # "do"
   $r[1] = array();
   jsp_block($r[3]);
   #-- while post condition
   jsp_get();   # "while"
   jsp_expect(JS_WHILE, false, "_constr_repeat");
   jsp_get();   # "("
   jsp_expect(JS_BRACES, "(", "_constr_repeat2");
   $r[2] = jsp_expr_start();
   jsp_get();   # ")"
   jsp_expect(JS_BRACES, ")", "_constr_repeat3");
   #-- add to parent bytecode stream
   $bc[] = $r;
}


# runtime/lang functions (echo, print)
#
function jsp_constr_rt(&$bc) {
   global $type, $val, $next, $nextval;
   $r = array(JS_RT, $type);
   jsp_get();
   jsp_append_list($r, JS_END, ";");
   $bc[] = $r;
}


# reads one command/expr/line;
#
function jsp_code_lines(&$bc, $term=JS_END) {
   global $type, $val, $next, $nextval;

   jsp_getnext();
   while ($type && ($type!=$term)) {

      switch($type) {

         case JS_CURLYBR:
            if ($val=="{") {
               $bc[] = array();
               $jsp_block($bc[count($bc)-1]);
            }
            else {
               return;
            }
            break;

         case JS_FOR:
            jsp_constr_for($bc);
            break;

         case JS_IF:
            jsp_constr_if($bc);
            break;
         case JS_WHILE:
            jsp_constr_while($bc);
            break;
         case JS_DO:
            jsp_constr_do($bc);
            break;

         case JS_PRINT:
            jsp_constr_rt($bc);
            break;

         case JS_END:
            break;

         default:
            $bc[] = jsp_expr_start();
      }

      #-- end of line
      while ($next == JS_END) {
         jsp_get();
      }
      if (($type==JS_CURLYBR) && ($val=="}")) {
         jsp_getnext();
         return;
      }

      jsp_getnext();
   }
}


# parses a block of code
#
function jsp_block(&$bc, $term=JS_CURLYBR) {
   global $type, $val, $next, $nextval;

   jsp_get();
   jsp_expect(JS_CURLYBR, "{", "_block_{");

   $bc = array();
   jsp_code_lines($bc, JS_CURLYBR);
#echo "_P_BLOCK,$type,$next:\n";
#print_r($bc);

   jsp_get();
   jsp_expect(JS_CURLYBR, "}", "_block_}");
   jsp_getnext();
}


#---------------------------------------------------------------------
#---------------------------------------------------------------------


       
#------------------------------------------------------------------------------
 ## ##   ## ###### ###### ######  ######  ######  ###### ###### ###### ######
 ## ###  ##   ##   ##     ##   ## ##   ## ##   ## ##       ##   ##     ##   ##
 ## #### ##   ##   ##     ##   ## ##   ## ##   ## ##       ##   ##     ##   ##
 ## ## ####   ##   ####   ######  ######  ######  ####     ##   ####   ######
 ## ##  ###   ##   ##     ## ##   ##      ## ##   ##       ##   ##     ## ##
 ## ##   ##   ##   ##     ##  ##  ##      ##  ##  ##       ##   ##     ##  ##
 ## ##   ##   ##   ###### ##   ## ##      ##   ## ######   ##   ###### ##   ##
#------------------------------------------------------------------------------


# runs the main program (in $bc["."])
#
function jsi_run()
{
   global $bc, $jsi_vars;
   jsi_mk_runtime_env();

   jsi_block($bc["."]);
}


# prepare variables
#
function jsi_mk_runtime_env()
{
   global $jsi_vars, $jsi_lvars, $jsi_funcs;
   $jsi_lvars = (array)$jsi_lvars;
   $jsi_vars = (array)$jsi_vars;
   $jsi_funcs = (array)$jsi_funcs;

   #-- pre-def vars
   $jsi_vars["system.version"] = JS_VERSION;

   #-- allowed system (PHP) funcs
   $jsi_funcs[] = "jsrt_write";
   $jsi_funcs[] = "jsrt_writeLn";
   $jsi_funcs[] = "time";
   $jsi_vars["write"] = "jsrt_write";
   $jsi_vars["document.write"] = "jsrt_write";
   $jsi_vars["writeLn"] = "jsrt_writeLn";
   $jsi_vars["document.writeLn"] = "jsrt_writeLn";
   $jsi_vars["system.time"] = "time";

   #-- std functions
   $add = array(
      "Math" => array(
         "abs", "acos", "asin", "atan", "ceil", "cos", "exp", "floor",
         "log", "man", "min", "pow", "random"=>"rand", "round", "sin",
         "sqrt", "tan",
      ),
   );
   foreach($add as $obj=>$d) {
      foreach ($d as $i=>$func) {
         $i = is_int($i) ? $func : $i;
         $jsi_vars["$obj.$i"] = $func;
         $jsi_funcs[] = $func;
      }
   }

   #-- std values
   $jsi_vars["Screen.width"] = 80;
   $jsi_vars["Screen.height"] = 25;
   $jsi_vars["Screen.pixelDepth"] = 4;
   $jsi_vars["Screen.colorDepth"] = 4;
}


function jsi_register_func($js_f, $php_f)
{
   global $jsi_vars, $jsi_funcs;

   $jsi_vars[$js_f] = $php_f;  // functions are also variables/objects
   $jsi_funcs[] = $php_f;
}


function jsi_err($s)
{
   echo "\nINTERPRETER ERROR: $s\n";
}






# executes a block of commands
# (grouped into a subarray)
#
function jsi_block(&$bc)
{
   $pc = 0;
   $pc_end = count($bc);
   for ($pc=0; $pc<$pc_end; $pc++) {

      if (is_array($bc[$pc]))  // else it is expression in void context
      switch ($bc[$pc][0]) {
         case JS_MATH:
         case JS_CMP:
         case JS_FCALL:
         case JS_VALUE:
         case JS_WORD:
         case JS_ASSIGN:
# echo "EXPRESSION= "; echo
            jsi_expr($bc[$pc]);
# echo "\n";
            break;
         case JS_FOR:
            jsi_cn_for($bc[$pc]);
            break;
         case JS_COND:
            jsi_cn_cond($bc[$pc]);
            break;
         case JS_RT:
            jsi_cn_rt($bc[$pc]);
            break;
         default:
            if (is_array($bc[$pc])) {
               jsi_block($bc[$pc]);
            }
            else {
               jsi_err("unknown processing code @$pc");
            }
      }
   }
}



#----------------------------------------------------------------------
#-- language constructs

function jsi_cn_for(&$bc) {
   while ($if=jsi_expr($bc[1])) {
      jsi_block($bc[3]);
      jsi_expr($bc[2]);
   }
}


# conditional statements (if, while, ...)
#
function jsi_cn_cond(&$bc) {

   #-- if/elseif/else
   if ($bc[0]==JS_IF) {
      for ($i=2; $i<count($bc); $i+=2) {
         if (jsi_expr($bc[$i])) {
            jsi_block($bc[$i+1]);
         }
      }
   }
   #-- while
   elseif ($bc[1] == JS_WHILE) {
      while (jsi_expr($bc[2])) {
         jsi_block($bc[3]);
      }
   }
   #-- repeat until / do while
   elseif ($bc[1] == JS_DO) {
      do {
         jsi_block($bc[3]);
      }
      while (jsi_expr($bc[2]));
   }
}


# runtime functions (pseudo-func calls)
#
function jsi_cn_rt(&$bc) {
   global $jsi_vars;
   $args = array();
   for ($i=2; $i<count($bc); $i++) {
      $args[] = jsi_expr($bc[$i]);
   }
   switch ($bc[1]) {
      case JS_PRINT:
         echo implode("", $args);
         break;

      default:
         break;
   }
}



#----------------------------------------------------------------------
#-- variable handling


# create new variable in jsi context
#
function &jsi_mk_var($name)
{
   global $jsi_vars;
   if (!isset($jsi_vars[$name])) {
      $jsi_vars[$name] = false;
   }
   return($jsi_vars[$name]);
}


# get contents of a jsi context variable
#
function jsi_get_var(&$bc)
{
   global $jsi_vars;
   return($jsi_vars[$bc[1]]);
}



#----------------------------------------------------------------------
#-- expressions


# runs a function (internal / external)
#
function jsi_fcall(&$bc)
{
   global $jsi_vars, $jsi_funcs;
   $r = 0;

   #-- a function is also a variable/object
   if ($name = $jsi_vars[$bc[1]]) {

      #-- system functions (PHP code)
      if (in_array($name, $jsi_funcs) && function_exists($name)) {
         $args = array();
         for ($i=2; $i<count($bc); $i++) {
            $args[] = jsi_expr($bc[$i]);
         }
         $r = call_user_func_array($name, $args);
      }

      #-- inline functions
      // (in separate $bc)
      // ...
   }
   return($r);
}


# variable = assignment code
#
function jsi_assign(&$bc)
{
   $var = &jsi_mk_var($bc[1][1]);
   $var = jsi_expr($bc[2]);
   return($var);
}



# evaluate the pre-arranged (parser did it all) expressions
#
function jsi_math(&$bc)
{
   $constant = 1;
   $val = NULL;
   for ($i=0; $i<count($bc); $i+=2) {

      $add = jsi_expr($bc[$i+1]);
      $constant = $constant && is_scalar($bc[$i+1]);

      switch ($bc[$i]) {

         #-- initial value
         case JS_MATH:
            $val = $add;
            break;

         #-- basic math
         case "+":
            if (is_string($var) || is_string($add)) {
               $val .= $add;
            }
            else {
               $val += $add;
            }
            break;
         case "-":
            $val -= $add;
            break;
         case "*":
            $val *= $add;
            break;
         case "/":
            $val /= $add;
            break;
         case "%":
            $val %= $add;
            break;

         #-- bit
         case "&":
            $val &= $add;
            break;
         case "|":
            $val |= $add;
            break;
         case "^":
            $val ^= $add;
            break;
         #-- unary operator "~" (only two args, the first always zero, unused)
         case "~":
            $val = ~$add;
            break;

         #-- bool
         case "&&":
            $val = ($val && $add) ?1:0;
            break;
         case "||":
            $val = ($val || $add) ?1:0;
            break;
         case "!":  // unary operation, first argument zero and unused
            $val = (!$add) ?1:0;
            break;

         #-- string
         case ".":
            $val .= $add;
            break;

         #-- error
         default:
            jsi_err("expression operator '$bc[$i]' fault");
      }
   }
   if ($constant) {   // replace tree with constant
      $bc = $val;
   }
   return($val);
}


# does the boolean math
#
function jsi_cmp(&$bc)
{
   $val = 0;
   $A = jsi_expr($bc[1]);
   $B = jsi_expr($bc[3]);
   switch ($bc[2]) {
      case "<":
         $val = ($A < $B) ?1:0;
         break;
      case "<=":
         $val = ($A <= $B) ?1:0;
         break;
      case ">":
         $val = ($A > $B) ?1:0;
         break;
      case ">=":
         $val = ($A >= $B) ?1:0;
         break;
      case "===":
         $val = ($A === $B) ?1:0;
         break;
      case "==":
         $val = ($A == $B) ?1:0;
         break;
      case "!==":
         $val = ($A !== $B) ?1:0;
         break;
      case "!=":
         $val = ($A != $B) ?1:0;
         break;
      default:
         jsi_err("unknown boolean operation '$bc[2]'");
   }
   if (is_scalar($bc[1]) && is_scalar($bc[3])) {
      $bc = $val;
   }
   return($val);
}


# huh, simple
#
function jsi_expr(&$bc)
{
   if (is_array($bc)) {
      switch ($bc[0]) {
         case JS_ASSIGN:
            return jsi_assign($bc);
            break;
         case JS_MATH:
            return jsi_math($bc);
            break;
         case JS_CMP:
            return jsi_cmp($bc);
            break;
         case JS_VALUE:
            $bc = $bc[1];
            return jsi_expr($bc);
            break;
         case JS_WORD:
            return jsi_get_var($bc);
            break;
         case JS_FCALL:
            return jsi_fcall($bc);
            break;
         default:
            jsi_err("expression fault <<".substr(serialize($bc),0,128).">>");
      }
   }
   else {
      return($bc);   // must be direct value
   }
}


#-----------------------------------------------------------------
#-- run time functions

function jsrt_write($p) {
   echo $p;
}
function jsrt_writeLn($p) {
   echo $p;
   echo "\n";
}


#-----------------------------------------------------------------
#-- end


?>