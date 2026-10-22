# Contributing to Ìpìlẹ̀

Thanks for helping. Bug reports, fixes and improvements are all welcome.

## Reporting a bug or asking for a feature

Open an issue and include what you expected, what happened and the steps to reproduce it. If the problem is a security vulnerability, **don't open an issue**. Follow [SECURITY.md](SECURITY.md) instead.

## Sending a change

1. Fork the repository and create a branch from `main`.
2. Set up the app with `composer setup`, then run it with `composer dev`.
3. Make your change. Read [the developer guide](project-docs/developer-guide.md) first: it explains where code goes and the conventions Ìpìlẹ̀ follows.
4. Before you push, run:

   ```bash
   php artisan test --compact
   vendor/bin/pint --dirty
   npm run typecheck
   ```

5. Open a pull request against `main`. Describe what changed and why, and link the issue it fixes.

A maintainer reviews every pull request. Nothing is merged without approval, and all checks must pass.

## Ground rules for changes

- **Admin pages and sensitive routes check a permission.** Being signed in is not enough.
- **Secrets are stored encrypted** and are never sent to the browser.
- **Operator settings live in the database and the admin area**, not in `.env` or code.
- **New behaviour comes with a feature test.**
- **Keep Ìpìlẹ̀'s own code domain-neutral.** It is a starter kit for any product.
- If you change something end users see, update the matching article in `resources/help/`.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
