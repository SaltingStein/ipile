# Widget data connection (MCP): integration guide

This guide is for the team running a **third-party assistant widget service** (for example BI Hub) that is embedded
in an Ìpìlẹ̀ site and wants to answer from that site's own data. It also covers what Ìpìlẹ̀ builders can add.

Ìpìlẹ̀ serves a read-only [Model Context Protocol](https://modelcontextprotocol.io) server at
`https://{site}/mcp/widgets`, built with Laravel's first-party `laravel/mcp` package inside the app. There is no
second process to run and no direct database access: every call runs as one signed-in person, with that person's own
permissions, under the site assistant's data policy.

Read [embed-integration-guide.md](embed-integration-guide.md) first: this connection builds on its **signed access**
claims.

---

## 1. What the site administrator sets up

In **Admin → Integrations → Third-party assistant widget** (permission `manage_integrations`):

| Field | Value |
|---|---|
| Access mode | `signed` (required: every request must belong to one person) |
| Data access | `on` |
| Data access networks | Your service's outbound IP addresses or CIDR ranges, comma-separated |

**Admin → Platform → Site assistant** must have **Third-party widget** as the assistant source. Business figures are
chosen there too (**Business figures it may read**).

While any of this is missing, `/mcp/widgets` answers `404`.

---

## 2. Authentication: forward the claim

With data access on, the claims the site signs for your widget carry one extra field, `ref`:

| Field | Meaning |
|---|---|
| `role`, `visitor`, `exp` | As in the embed guide |
| `ref` | An opaque reference encrypted with the site's own key. Do not parse or change it |

To read data for a visitor, send the **most recent claim** you received for them, unchanged, as a bearer token:

```http
POST /mcp/widgets HTTP/1.1
Host: site.example.com
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json
Accept: application/json, text/event-stream
```

The site refuses the claim (`401`) when:

- the signature does not match the shared secret, or the claim has expired (claims last at most 5 minutes; the widget
  renews them by itself, so always use the newest one);
- `ref` is missing, changed, or does not belong to this claim. Knowing the shared secret is not enough to sign a
  claim for another person, or to extend one;
- since the claim was issued, the person was signed out everywhere, changed their password, lost their mapped role
  or was suspended.

Requests from networks outside the configured list get `403`. There is no session or cookie: each request stands on
its own claim. The route allows 120 requests per minute per network (`429` beyond that).

---

## 3. Calling tools

The server speaks MCP over HTTP (Streamable HTTP, JSON-RPC 2.0, `POST` only). Use any MCP client library, or plain
JSON-RPC:

```bash
curl -s https://site.example.com/mcp/widgets \
  -H "Authorization: Bearer $CLAIM" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'

curl -s https://site.example.com/mcp/widgets \
  -H "Authorization: Bearer $CLAIM" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search-help-articles","arguments":{"query":"reset password"}}}'
```

Results come back as `result.content[0].text`, a JSON string (or a plain sentence when there is nothing to return).

### Tools

All tools are read-only (`readOnlyHint: true`) and answer only for the person the claim belongs to.

| Tool | Arguments | Returns |
|---|---|---|
| `search-help-articles` | `query` (string, a few keywords) | Up to 3 Help Center articles that person can see: title, address and text |
| `my-access` | none | Their site roles, permissions, whether two-factor sign-in is on, and their role in each of their teams. No names or contact details |
| `business-figures` | `figure` (one of the keys listed in the tool's input schema) | Counts and totals for figures the administrator allowed **and** the person's permissions cover |

`tools/list` gives each tool's description and exact input schema; the `figure` list differs from person to person.
Sites can add their own tools (section 6), so list them rather than hard-coding the set.

### Errors

| What you get | Meaning |
|---|---|
| HTTP `404` | Data access is off, or the site's assistant source is not the third-party widget |
| HTTP `403` | Your request came from a network that is not allowed |
| HTTP `401` | Missing, expired, forged or outdated claim (see section 2) |
| HTTP `429` | Too many requests from your network |
| `result.isError: true`, text `This tool could not be run.` | The tool failed. No further detail is given, by design |
| Text `This information is not available to the assistant.` | The tool tried to read protected data and was stopped. Do not retry |
| Text `That figure is not available to this person. Do not guess it.` | The person may not see that figure |

Error responses never include table names, columns, queries or stack traces, even when the site runs in debug mode.

---

## 4. What the site records

Every call is recorded in the site's security log (Aegis, category **Assistant widget data**):

- `mcp.tool_called`: the person, the tool name and the outcome (`ok`, `blocked` or `error`). Never the arguments or
  the data returned.
- `mcp.access_refused`: the reason (`network` or `claim`), with no person attached.

Both are scored like any other security event. Because your calls come from your servers rather than the person's
browser, a read can raise at most an alert for that person; it can never sign them out or suspend them.

---

## 5. Local testing

1. On a local Ìpìlẹ̀ site, configure the widget integration with data access on and `127.0.0.1` as the network.
2. Mint a claim for a test account:

   ```bash
   php artisan tinker --execute 'echo app(App\Services\Assistant\AssistantWidget::class)->claim(App\Models\User::where("email", "you@example.com")->firstOrFail());'
   ```

3. Open the MCP Inspector against the route and add the header `Authorization: Bearer <claim>` in its connection
   settings:

   ```bash
   php artisan mcp:inspector mcp/widgets
   ```

The claim expires after the claim lifetime (5 minutes at most); mint a new one when it does.

---

## 6. For Ìpìlẹ̀ builders: adding tools

The widget gets Ìpìlẹ̀'s three tools above. The built-in assistant's `MyAccount` and `MyTeams` tools are left out on
purpose: they return names and emails, which this connection does not hand to a third party.

To offer one of your own tools, write it as a site assistant tool (it extends `App\Ai\Tools\AssistantTool`, so it acts
for one person and its queries run under `AssistantDataPolicy`) and opt it in separately from the built-in assistant:

```php
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\WidgetDataTools;

// In a service provider's boot():
AssistantDataPolicy::block('bank_accounts', ['account_number']);
WidgetDataTools::register(MyWalletSummary::class);
```

- Check your own permissions inside `read()`; the widget may call any tool it is offered.
- Return counts, totals and the person's own records only. Remember the data leaves your site.
- The tool's MCP name is its class name in kebab case (`MyWalletSummary` becomes `my-wallet-summary`).
- Registration is append-only: nothing can remove Ìpìlẹ̀'s tools or loosen the data policy.
- Cover it with a feature test that posts to `/mcp/widgets` with a claim from `AssistantWidget::claim()`, as
  `tests/Feature/Mcp/WidgetDataServerTest.php` does.
