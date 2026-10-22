---
name: ipile-help-docs
description: "Writes and updates end-user help articles in the Ìpìlẹ̀ Help Center (resources/help/*.md, shown at /help). Use whenever a change adds or alters something end users see or do, when the developer asks for user documentation, a help page, FAQ or how-to, or when a ipile:page help draft needs filling in."
---

# Ìpìlẹ̀ help articles

End-user help lives in `resources/help/*.md` and appears in the app at `/help` (linked in every user's sidebar). Keep it in step with the product: when you add or change something users see, create or update the matching article **in the same change**, then tell the developer which articles you touched.

## File format

`resources/help/{slug}.md`, slug in lowercase-kebab-case:

```markdown
---
title: Monthly reports
summary: See and download your team's monthly figures.
order: 60
permission: view_reports
draft: true
---

Short intro: what this is for, in one or two sentences.

## Download a report

1. Choose **Reports** in the menu.
2. Pick the month.
3. Choose **Download**.
```

| Field | Meaning |
|---|---|
| `title`, `summary` | Shown in the list and search; summary is one sentence |
| `order` | Lower comes first (starter articles use 10–50) |
| `permission` | Optional: hidden from users without it. Match the page's own permission |
| `draft: true` | Only shown while the app runs locally. Use it on new articles so the developer reviews them; the developer removes it |

Markdown only: raw HTML is stripped and unsafe links dropped. Tables, lists, bold and links work. Link other articles as `/help/{slug}`.

## Writing for end users

- Say what people can do and the steps to do it. No code, file paths, class names or developer terms.
- Use the exact labels on screen, in **bold**: buttons, menu items, field names. Check them in the `.tsx` file.
- Short sentences, numbered steps for procedures, one task per section.
- Mention limits people will hit (e.g. "the link works for 7 days") and what to do when something goes wrong.
- Describe behaviour that exists now. Do not promise upcoming features.
- Do not document admin-only internals in general articles; give admin topics an admin permission.

## When not to write one

Purely internal changes (refactors, background jobs, admin-only plumbing with no visible effect). If unsure, ask the developer in one sentence.

## Check

`php artisan test --compact tests/Feature/HelpCenterTest.php` (front matter parses, permissions respected), then open `/help/{slug}` locally to read it as a user would.
