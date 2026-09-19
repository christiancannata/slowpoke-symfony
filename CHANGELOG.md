# Changelog

## 0.1.1 - 2026-09-19

Nothing changes in the bundle itself: the first public run of the test matrix found two things in
the tests and fixed them.

- The test application kept its cache under a directory named after the vendor directory, which in
  a plain install is literally `vendor/`: the origin finder skips everything inside one, so the
  compiled Twig templates were never resolved and a query in a template pointed at the controller.
  The check that a template keeps its own line is back to testing what it says.
- The cache warmup test asked the application for every optional warmer, which on Symfony 5.4 with
  a current Twig dies inside twig-bundle. It asks this bundle's warmer instead, which is its
  subject. `symfony/error-handler` is pinned with the other components in the matrix, or Composer
  installs a newer major next to Symfony 5.4.

## 0.1.0 - 2026-09-18

First release.

- Every request is one trace: the route template (`/orders/{id}`), the status, and each Doctrine
  query with the `file:line` of the application code that ran it — including the Twig template,
  when the query comes from a template. Sent on `kernel.terminate`, after the response has left.
- The N+1 that hides in a loop is visible as what it is: the same statement, counted, with the one
  line behind it.
- Messenger messages and console commands (cron) are traced too, one trace each, with their
  queries. Workers are left alone: `messenger:consume` would hold one trace open for hours.
- Symfony 5.4, 6.4 and 7.x, PHP 7.4 to 8.4, Doctrine DBAL 3.3+ and 4. DBAL 2 is not supported.
- Statement text only, never the bound values. Plain `http` to the agent on the same machine or a
  private network, with a 0.1 s budget: a missing or slow agent costs a trace, never a request.
