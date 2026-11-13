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

Suite state (bundled tests/unit, 1,335 tests):

## Oracle-differential result (the acceptance comparison)

Environment: Apple Containers, Linux/arm64, MariaDB 10.11,
Postgres 16, Redis 7, Memcached, sqlite, apc.enable_cli=1,
en_US.UTF-8 locale, E_ALL, fresh DB per isolated test.

- Oracle (PHP 7.0.33 + stock v3.4.3): 8 errors + 6 failures full-run;
  every isolated test passes
- Port (PHP 8.5.4): 4 errors + 11 failures full-run; every isolated
  test passes except one (ViewTest Issue12648 - asserts PHP 7's exact
  undefined-variable warning text; PHP 8 changed the message)

Full-run residual failures on both stacks are cross-test DB-state
ordering artifacts (Codeception 2 and 5 iterate test directories in
different orders; the suite assumes per-CI-run fresh DBs). The correct
comparison is per-test isolation with a fresh schema, where the port
matches the oracle on all 19 previously-divergent tests.

Real port bugs found by this differential (all fixed):
1. Escaper::detectEncoding ARM64 signed-char misdetection (also an
   upstream 3.4.3 bug on ARM64)
2. Debug\Dump: baked PHP 7 ReflectionProperty constants (256/512/1024
   vs PHP 8's 1/2/4) - detailed dumps printed nothing
3. LSB scope lost on static calls through name-based resolution
   (fixed by direct fcc resolution in zephir_fcall_resolve)
4. Crypt: cipher availability check was case-sensitive + zero-length
   IV crash on ECB ciphers (AES-256-ECB/-CFB verified working)

Encryption audit (Linux/PHP 8.5.4/OpenSSL 3.5, empirical):
- Works: AES-{128,192,256}-{CBC,CFB,CFB1,CFB8,CTR,OFB,ECB}, ARIA,
  Camellia, ChaCha20, SM4, 3DES, key wrap variants (87 total)
- Fails by design: AEAD (GCM/CCM/OCB) - Phalcon 3.4 Crypt API has no
  tag parameter (same on PHP 7)
- Blocked by OpenSSL 3 default policy: RC4, IDEA (legacy provider)
- AES-256-ECB and AES-256-CFB both work (case-insensitive cipher names)

Remaining open items:
- PHP 8.6 GA re-verification (~2026-11-19, the code already builds
  warning-clean against RC7)

## DB Matrix (Linux/arm64)

| Database | Result |
|---|---|
| MySQL 8.4.13 | Full suite: same profile as MariaDB |
| MariaDB 10.11 | Full suite: 4 errors + 11 failures (all order-dependent, pass isolated) |
| MySQL 5.7 | No arm64 image exists; run on amd64 when available |

## Serializable-interface decision: KEEP

Cross-version payload testing (PHP 7.0.33 generated on the oracle
container, un-serialized on the PHP 8.5.4 port):

| Class | PHP 7 payload → PHP 8.5 un-serialize |
|---|---|
| Phalcon\Mvc\Model\Resultset\Simple (real DB result) | OK (8 items, full data) |
| Phalcon\Config (nested) | OK |
| Phalcon\Acl\Role | OK |
| Phalcon\Validation\Message | OK |
| Phalcon\Session\Bag | OK |
| Phalcon\Cache\Backend\Memory | class restored OK; stored data NULL — identical on the PHP 7 oracle round-trip (by upstream design: the Serializable payload stores the frontend, not the data) |

The 3 startup deprecation notices (Serializable interface) remain
harmless: PHP 7 payloads un-serialize correctly and the interface is
not removed until PHP 9.
