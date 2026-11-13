/*

Compatibility shims for building the Phalcon 3.4.x (PHP 5/7 era) amalgamated
sources on PHP 8.x. Every shim maps an API that PHP 8 removed to its official
replacement documented in php-src UPGRADING.INTERNALS. Nothing here changes
runtime behavior: each replacement is the exact documented equivalent of the
removed symbol.

Include this header from php_phalcon.h immediately after <php.h> so that all
kernel and generated code sees the shims.

*/

#ifndef PHALCON_PHP8_COMPAT_H
#define PHALCON_PHP8_COMPAT_H

#if PHP_VERSION_ID >= 80000

/* PHP 8.0 removed the TSRMLS_* macros (ZTS has been transparent since 7.0).
 * The generated code still spells them out in prototypes and calls. */
#ifndef TSRMLS_D
#define TSRMLS_D
#endif
#ifndef TSRMLS_C
#define TSRMLS_C
#endif
#ifndef TSRMLS_DC
#define TSRMLS_DC
#endif
#ifndef TSRMLS_CC
#define TSRMLS_CC
#endif
#ifndef TSRMLS_FETCH
#define TSRMLS_FETCH()
#endif

/* PHP 8.0 removed ZEND_ACC_DTOR from function registration flags. */
#ifndef ZEND_ACC_DTOR
#define ZEND_ACC_DTOR 0
#endif

/* PHP 8.0 removed the `ulong` typedef from its headers. */
#define ulong unsigned long

/* PHP 7.4's SEPARATE_ZVAL_IF_NOT_REF, verbatim from Zend/zend_types.h
 * (PHP-7.4). Removed in PHP 8.0; every API it uses still exists in 8.x. */
#ifndef SEPARATE_ZVAL_IF_NOT_REF
#define SEPARATE_ZVAL_IF_NOT_REF(zv) do {				\
	zval *__zv = (zv);								\
	if (Z_TYPE_P(__zv) == IS_ARRAY) {				\
		if (Z_REFCOUNT_P(__zv) > 1) {				\
			if (Z_REFCOUNTED_P(__zv)) {				\
				Z_DELREF_P(__zv);					\
			}										\
			ZVAL_ARR(__zv, zend_array_dup(Z_ARR_P(__zv)));\
		}											\
	}												\
} while (0)
#endif

#if PHP_VERSION_ID >= 80100
/* PHP 8.1 removed the spl_ce_<Interface> alias class entries in favor of
 * the zend_ce_* symbols (UPGRADING.INTERNALS). Only Countable is used. */
#define spl_ce_Countable zend_ce_countable
#endif

#if PHP_VERSION_ID >= 80300
/* PHP 8.3 removed fast_add_function(); add_function() is the replacement. */
#define fast_add_function(result, op1, op2) add_function(result, op1, op2)
#endif

#if PHP_VERSION_ID >= 80500
/* PHP 8.5 removed zend_exception_get_default(); use zend_ce_exception.
 * Variadic because the generated code calls it with a TSRMLS_C argument. */
#define zend_exception_get_default(...) zend_ce_exception
#endif

#if PHP_VERSION_ID >= 80600
/* PHP 8.6 removed the zval_dtor() alias; it was zval_ptr_dtor_nogc(). */
#define zval_dtor(zv) zval_ptr_dtor_nogc(zv)
#endif

#endif /* PHP_VERSION_ID >= 80000 */

#endif /* PHALCON_PHP8_COMPAT_H */
