# Phalcon 3.4.x → PHP 8.5/8.6 Port

This branch (`port/php8`) ports the Phalcon 3.4 extension to PHP 8.5 and
8.6 while preserving 3.4.x runtime behavior. Every change is a patch to
the upstream-generated C (`build/php7/64bits/`, the PHP 7 amalgamated
build); `git diff oracle-upstream-head..port/php8 -- build/` reviews the
entire port.

## Lineage

- Base: upstream `phalcon/cphalcon` branch `3.4.x` @ `d4e03622`
  (= v3.4.5 + two unreleased fixes; tag `oracle-upstream-head`).
  The production reference is v3.4.3 (tag `oracle-3.4.3`).
- No third-party port code was merged; the Jumbleberry `3.4.x_php8`
  branch was used as reference knowledge only.

## What was changed (all inside `build/php7/64bits/` unless noted)

1. **php8_compat.h** (new): shims for APIs PHP 8 removed, each mapped to
   its documented replacement: TSRMLS_* (empty), ZEND_ACC_DTOR,
   ulong/php_uint32 typedefs, SEPARATE_ZVAL_IF_NOT_REF (verbatim PHP 7.4
   macro), spl_ce_Countable→zend_ce_countable (8.1),
   zend_exception_get_default→zend_ce_exception (8.5),
   zval_dtor→zval_ptr_dtor_nogc (8.6), fast_add_function→add_function (8.3).
2. **Removed headers**: php_smart_string.h (8.5), php_rand.h/php_lcg.h
   (8.4, → ext/random/php_random.h).
3. **Object handlers adapted to PHP 8 signatures** (zval* →
   zend_object*/zend_string*): read/write/unset_property, get_properties,
   clone_obj, count_elements, get_closure (+check_only), zend_update_property*,
   zend_call_method*, php_implode, php_stat, zend_compile_string (position
   arg, 8.2), zend_forbid_dynamic_call, zend_file_handle.filename (8.1,
   via zend_stream_init_filename_ex), ZEND_ACC_SHADOW, fci.no_separation,
   IS_CALLABLE_CHECK_SILENT, php_pcre_match_impl (8.6, 7-arg form),
   Z_COPYABLE_P → IS_ARRAY check (8.6), _zval_get_string_func rename (8.6),
   mt_rand via the 8.2 ext/random engine.
4. **zend_fcall_info/fcall_info_cache are zero-initialized** before use:
   PHP 8 added fields (named_params, …) that old stack declarations left
   as garbage - this single class of bug produced every "Invalid callback
   …" failure during bring-up.
5. **$this binding**: PHP 8's zend_call_function() takes $this from
   fci_cache->object only; name-based callable resolution leaves it NULL,
   so the kernel restores object + called_scope (late static binding)
   after resolution. resolve_callable() uses real class names for
   self/static/parent (string forms are deprecated in callables).
6. **arginfo for all methods** (`tools/gen_arginfo.py`, generates
   `phalcon_arginfo.h`): parameter names/arities parsed from the same
   `phalcon/` zep sources (extends/implements chains, `{ get, set,
   __toString }` property shortcuts, $-prefixed names). Also declares
   PHP 8.1 tentative return types for the ~105 interface-conformance
   methods (real `string` for `__toString`), killing the
   ReturnTypeWillChange deprecations while keeping violations
   deprecations (not TypeError).
7. **Dynamic properties** (PHP 8.2 deprecation): Config, Di\Injectable,
   Mvc\Model, Mvc\Collection, Mvc\Collection\Document, Mvc\Model\Row set
   ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES - they intentionally store user data
   as dynamic properties (PHP 7 semantics; the flag is inherited by
   userland models).
8. **Volt `join` filter**: the compiler emitted join(<piped>, <glue>) -
   the historic swapped implode order PHP 8 removed. The generated
   compiler branch now swaps the first two top-level arguments and emits
   implode(<glue>, <piped>) (identical on all versions).
9. **DateTime::getLastErrors()** (Validation\Validator\Date): PHP 8.2
   returns false when there were no errors; guarded.
10. **CreditCard Luhn sum**: PHP 8 array_sum() rejects non-numeric
    strings; digits are summed manually, keeping PHP 7 behavior for
    invalid-card payloads.

## Deliberately NOT changed

- `Phalcon\Config` v3 implementation, model instance reuse, and every
  other behavioral choice stay upstream 3.4.x.
- The 3 classes implementing `Serializable` (Resultset\Simple/Complex,
  Cache\Backend\Memory) keep it: dropping it would break unserializing
  PHP 7 session/cache payloads. The 3 startup deprecation notices stay
  until cross-version payload fixtures decide otherwise.

## Build

    cd build/php7/64bits
    phpize && ./configure && make
    php -d extension=modules/phalcon.so ...

The upstream `build/` directory is committed read-only (mode 444);
`chmod -R u+w build/php7/64bits/build` once after checkout if phpize
needs to refresh its files.

## Test (the acceptance metric: the bundled suite)

Requires MySQL on 127.0.0.1:3306 (root, no password) loaded with
tests/_data/schemas/mysql/phalcon_test.sql:

    COMPOSER=composer-port.json composer install
    scripts/run-suite.sh "$(which php)" build/php7/64bits/modules

Suite state (macOS arm64, MySQL 8.4 loaded with the bundled schema):

- PHP 8.5.10: 1,335 tests, 6,202 assertions, 35 errors + 19 failures
- PHP 8.6.0RC7: 1,335 tests, 34 errors + 17 failures (same distribution)

Every remaining failure is environment-bound, verified one by one:
Postgres absent (9), CLI session/locale warnings (15, macOS),
AES-128-ECB blocked by OpenSSL 3 policy (2), sqlite-context model
metadata (6), and a few runner artifacts. Verified real port bugs fixed
along the way: Volt join, require-file-handle corruption (SIGBUS),
fcall garbage, Date validator, Luhn, twig engine fixtures.

Open items (need a Linux environment; the local VM would not boot):
- re-run the suite on Linux (glibc iconv //TRANSLIT, locales) and the
  MySQL 5.7/8.x/MariaDB matrix (see ci-port/compose.yml)
- differential pass against the PHP 7.0 + v3.4.3 oracle
- Serializable-interface decision for the 3 classes (PHP 7 session and
  cache payload fixtures)
