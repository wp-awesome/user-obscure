# user-obscure

Username and account-slug exposure control for WordPress. Five surfaces, five separate decisions,
and one settings page that means what it says.

One of the **wp-awesome** plugins. Each is its own repository so a project adopts only what it wants:

| plugin | repository | what it answers |
| --- | --- | --- |
| `site-access` | `wp-awesome/site-access` | May this address reach this site at all? |
| `user-obscure` | `wp-awesome/user-obscure` | What may an allowed visitor learn about its accounts? |

They share no code, no options and no function prefix. Adopt either, both, or neither.

```sh
git submodule add https://github.com/wp-awesome/user-obscure \
    wp-content/plugins/user-obscure
```

Then activate it, and tick the surfaces you want at **Settings → User Obscure**. Nothing is obscured
until you do.

## Read this part first

**This does not stop a password attack.** Rate limiting and two-factor authentication do that.
WordPress treats usernames as non-secret by design, and this plugin does not change that design — it
removes a free reconnaissance step that comes before an attack, on the surfaces where WordPress hands
the list over to anybody who asks. If you install this and nothing else, you have made the first step
of credential stuffing slower and left every subsequent step exactly as it was.

Everything this does *not* cover is listed under [What this does not achieve](#what-this-does-not-achieve),
and that list is longer than the feature list. Read it before you describe this plugin to anybody.

## The problem, measured

A sibling project ran SiteGround Security with `disable_usernames=1` and believed it was protected.
That setting hooks `illegal_user_logins` and nothing else — it blocks *creating* a user named
"admin", and has never touched a read surface. With it enabled,
`/wp-json/wp/v2/users?per_page=100` returned sixteen real accounts with their login slugs.

All five surfaces, confirmed on a second production-shaped site:

```
GET  /wp-json/wp/v2/users?per_page=100   200, slugs: site_admin, jane_editor
GET  /?author=1                          301 -> /author/site_admin/
GET  /author/site_admin/                 200   (/author/nosuchuser/ -> 404)
GET  /wp-json/oembed/1.0/embed?url=/     author_name "Andrea Lam", author_url carrying the slug
POST /wp-login.php                       unknown user -> "not registered"
                                         real user    -> "the password you entered"
```

Two things to take from that listing. The REST `slug` field **is** the login name — it is not a
display name, not a nickname, it is what goes in the username box. And the login-error difference is
the sharpest of the five: a username oracle that needs no REST access, no permalink structure and no
author archive, just the form every WordPress site has.

## The five surfaces

| # | Surface | Where it is answered | Kind of decision |
| --- | --- | --- | --- |
| 1 | `/wp-json/wp/v2/users` | `rest_pre_dispatch` | setting |
| 2 | `/?author=N` | `parse_request` → `template_redirect` | setting |
| 3 | `/author/<slug>/` | `parse_request` → `template_redirect` | **constant** |
| 4 | `/wp-json/oembed/1.0/embed` | `oembed_response_data` | setting |
| 5 | `wp-login.php` error text | `authenticate` | setting |

### 1. The REST user listing

**The route is not removed.** Unregistering `/wp/v2/users` is the recipe everybody posts and it
breaks the block editor's author panel, several plugins, and anything else that reads the route while
logged in. What is gated here is the *answer*, per request, on capability. Deny is a 401 with core's
own `rest_user_cannot_view` code — not an empty `200`, which is a lie some client will cache.

The gate is `list_users` **or** `edit_posts`, and the second half is not a weakening. `list_users` is
an administrator capability; an Editor does not have it. Gating on `list_users` alone takes the
author selector away from every Editor on the site, and it does so silently — the panel simply stops
populating. Core serves that panel to anyone who can create content, through a request shape whose
parameter name has already changed twice: `who=authors`, then `has_published_posts`, then a
`capabilities` parameter. Keying on the parameter name would make the editor break on a core upgrade.
Keying on capability cannot.

The cost, stated plainly: **this hides nothing from anybody who already holds an authoring account.**
A Contributor can still enumerate. Core already permits that, so nothing is lost relative to stock,
but if your threat model includes people with accounts, this is not your control.

`/wp/v2/users/me` is never denied — it is a user reading their own profile, which the dashboard and
the editor both do, and it enumerates nobody. Nor is anything *below* the users route:
`/wp/v2/users/5/application-passwords` is left to core's own per-user `edit_user` check, because a
prefix match there would take self-service application passwords away from every non-administrator.

### 2. `?author=N` probing

The `301` **is** the leak, not the page it points at. `/?author=1` answering
`301 -> /author/site_admin/` hands over a login slug for an account id before the archive is ever
fetched. Closing it takes two things, and only one of them is the 404: core answers a 404 by trying
`redirect_guess_404_permalink()`, so `redirect_canonical` is suppressed for that request as well.

`wp-admin/edit.php?author=5` — how an administrator filters the posts list — is exempt. An `author`
query variable that is not an integer cannot resolve to an account and is left alone; it belongs to
somebody else.

### 3. Author archives

`/author/site_admin/` answering 200 while `/author/nosuchuser/` answers 404 is a valid-username
oracle for anybody who already has a candidate list. Closing it means 404ing a URL shape uniformly,
and **a site that genuinely uses author archives cannot do that** — the pages exist, the theme links
to them, and search engines may have indexed them.

So this one is a **declaration in code**, not a checkbox. See
[Why one of the five is a constant](#why-one-of-the-five-is-a-constant).

When it is declared unused, a real slug and an invented one get the identical 404. That sameness is
the fix. A 403, or a redirect home, would leave the difference measurable and achieve nothing.

### 4. oEmbed

The surface people forget. It is served by a different controller under a different namespace, so it
survives every `/wp/v2/users` recipe — measured on a site whose REST users route had already been
thought about. `author_name` and `author_url` are **unset**, not blanked: oEmbed makes both optional,
so an absent field is a shape every consumer already handles, while an empty string renders as an
empty byline.

### 5. Login errors

Normalised on `authenticate`, not on `login_errors`. The `login_errors` filter receives rendered HTML
from `wp-login.php` only; `authenticate` receives the `WP_Error` itself, and it is the single path
through which XML-RPC, the REST cookie flow and every other consumer of `wp_authenticate()` also
pass. Normalising the error rather than the string flattens all of them at once.

Three codes are flattened — `invalid_username`, `invalid_email`, `incorrect_password` — into one code
with one message. When one of them is present, *every* code on that error is replaced: leaving a
second one attached would restore the difference, because the set of codes would still say which
branch the request took. `empty_username`, `empty_password`, an expired session and a spam-check
refusal are untouched and keep their own messages, because a login form that answers everything with
one sentence is a form nobody can debug.

**`wp_login_failed` still fires, still carrying the attempted username.** Rate limiters and
login-attempt logs keep working unchanged. That is deliberate: those are the controls that do the
real work, and this must not damage them to protect a reconnaissance step.

## Setting, or constant?

Four of the five are stored settings. One is a constant. They are different kinds of question, and
treating them as one on/off switch would produce a plugin nobody can enable — whether a surface is
legitimately used varies per site, and a single switch forces the site with one legitimate use to
forgo all five.

**The four settings are decisions with no site-shape precondition.** Nothing in the theme's
templates depends on `?author=N` resolving; WordPress writes `/author/<slug>/` links, not that form.
Nothing renders `author_name` from the oEmbed discovery JSON except a third-party embedder. Nothing
on a normal site reads `/wp/v2/users` anonymously. The operator can tick any of them, see the effect
immediately, and untick it from the same screen thirty seconds later. That is an operator decision
and it belongs in the database, on a settings page, where the person who runs the site can reach it.

### Why one of the five is a constant

Author archives are different in kind, on every axis that matters:

- **The answer is a fact, not a preference.** Whether the theme links to author archives is
  determined by its templates. There is a correct answer and it is discoverable only by reading code.
- **Nobody can check it from the dashboard.** An operator looking at a checkbox labelled "404 author
  archives" has no way to find out whether their theme uses them.
- **Getting it wrong breaks pages that exist.** Not "a feature stops working" — published, linked,
  possibly indexed URLs start returning 404, and the damage is diffuse and slow to notice. Every
  other surface fails loudly and immediately: an integration 401s, an embed loses a byline.

So it is declared in code, where it is reviewed, versioned, and set by somebody who has read the
templates:

```php
// wp-config.php, or a small mu-plugin
const WP_USER_OBSCURE_AUTHOR_ARCHIVES = 'unused';
```

The settings screen **shows** the declaration, names the constant, and says to ask a developer. It
does not render it as a disabled checkbox — that reads as "you lack permission" when the truth is
"this is code", and it is the same lie in a different shape.

**Why a two-value string and not a boolean.** `(bool) 'unused'` is `true`, and so is
`(bool) 'Unused'`. A boolean-shaped constant resolves both of the near misses a developer actually
makes to whichever direction `true` happens to mean, and there is no arrangement in which both fall
safe. An exact comparison against one spelling has exactly one failure direction, and it is the safe
one: anything that is not the exact string `unused` means *used*, which contributes nothing.

## The fail-safe rule, and why it is the opposite of the sibling's

**A value this plugin cannot read means "do not obscure".**

`site-access` resolves an unreadable value to its *most restrictive* scope, because what it protects
— unreleased content behind a gate — is worth a dark site. This plugin protects a reconnaissance
step, not a secret. Turning a surface on after a corrupt restore would 401 an integration or 404 a
published URL in exchange for a benefit that, on its own, stops nothing. **So this one fails towards
the site working, and the asymmetry between the two plugins is a decision, not an oversight.**

Concretely: only the literal `'1'` means on. Missing, empty, `'0'`, `'on'`, `'true'`, `'yes'`,
`' 1 '`, an array, an object and `null` are all the same case — off. The checkbox cannot produce any
of them, so a value arriving in one of those shapes came from a restore, a hand-edited row or another
plugin, and the safe reading of a value nobody chose is *nothing was asked for*.

One corrupt row switches its own surface off and leaves the other three alone. That is why there are
four options rather than one serialised array.

**Fail closed by contributing nothing, never by throwing.** Every callback here runs on a site it is
not allowed to take down. No array is cast to a string, no undeclared constant is read, no option
value is assumed to be a string, no `$wp_query` is assumed to have the method it normally has. A
malformed anything costs this plugin its effect, never the site its availability — and never a
blanket denial that breaks the editor.

**A corrupt value is reported, not silently resolved.** "Off because nobody ticked it" and "off
because the row holds something unreadable" are different states, and a screen that renders them
identically is how a control stays broken for months. The screen names the option and says which way
it resolved.

## Why this is an ordinary plugin, and its sibling is not

`site-access` must be must-use: it denies a request before WordPress has finished booting, and a gate
that can be switched off from the dashboard it protects is not a gate.

Nothing here has that property. All five surfaces are answered on hooks — `rest_pre_dispatch`,
`parse_request`, `template_redirect`, `oembed_response_data`, `authenticate` — and every one of them
fires long after ordinary plugins have loaded. Must-use placement buys no earlier position.

It would cost something, and the cost points the other way. The realistic failure of this plugin is
that it 401s or 404s a surface some site legitimately uses. An ordinary plugin can be deactivated by
the person who noticed, in the screen they noticed it in; a must-use plugin needs shell access. When
the likely failure is *"I broke the editor"*, **being deactivatable is the safety property.**

No stub file is needed, either. WordPress does not recurse into `mu-plugins/` subdirectories — the
trap that makes `site-access` need a top-level stub — but it does recurse into `wp-content/plugins/`,
so a submodule works as a plugin directly.

A host that wants it must-use anyway can `require` the entry point from a top-level mu-plugin stub.
That is supported, and the reason it is supported is worth stating: **nothing here reads an option,
calls `current_user_can()`, or consults a filterable value at load.** `wpuo_boot()` only registers
callbacks. Every read happens inside a callback, by which time the whole filter chain exists.

That matters because the sibling carries a documented residual from the opposite arrangement: it
derives the login path from `wp_login_url()` at mu-plugin load, where a hide-login plugin's filter is
not yet attached, so a relocated login form stays reachable. There is no equivalent here, and
`tests/boot-test.php` is how it stays that way — it fences off the database, so `get_option()` throws,
and then boots.

```php
$report = wpuo_boot();
// ['hooks' => ['rest_pre_dispatch', 'parse_request', 'oembed_response_data',
//              'authenticate', 'shake_error_codes'],
//  'settings' => true|false]

$state = wpuo_report();
// ['rest_users' => bool, 'author_probe' => bool, 'author_archives' => 'used'|'unused',
//  'oembed' => bool, 'login_errors' => bool]
```

The boot report lists **hooks, not settings**, deliberately: stating the settings there would mean
reading the database at load, which is the one thing the fence forbids. `wpuo_report()` answers that
question on demand, from inside a request.

## There is no environment override

`site-access` shipped `WP_AWESOME_GATE_PRODUCTION_NOOP`, which made its gate inert whenever the
environment was `production`. It was **removed**, for lying to the operator: a settings page offering
modes must mean those modes.

Nothing equivalent exists here, and none may be added. A ticked box obscures that surface, in every
environment, with no constant able to quietly countermand it. `tests/settings-test.php` asserts the
absence directly — the sources are searched for `wp_get_environment_type`, `WP_ENVIRONMENT_TYPE` and
`NOOP`, and reintroducing any of them fails that test by name.

## Host constants

All optional. A host that defines nothing gets working defaults under generic option keys, with every
surface switched off until somebody ticks it.

| Constant | Default | Purpose |
| --- | --- | --- |
| `WP_USER_OBSCURE_AUTHOR_ARCHIVES` | `used` | `unused` declares that this site does not use author archives, so they may 404. |
| `WP_USER_OBSCURE_OPTION_REST_USERS` | `wp_user_obscure_rest_users` | Option key for surface 1. |
| `WP_USER_OBSCURE_OPTION_AUTHOR_PROBE` | `wp_user_obscure_author_probe` | Option key for surface 2. |
| `WP_USER_OBSCURE_OPTION_OEMBED` | `wp_user_obscure_oembed` | Option key for surface 4. |
| `WP_USER_OBSCURE_OPTION_LOGIN_ERRORS` | `wp_user_obscure_login_errors` | Option key for surface 5. |

The option keys are injectable for the same reason the sibling makes its keys injectable — a site
that already stores one of these toggles under its own key should not need a migration to adopt this.
Unlike the sibling there is **no legacy schema to come from and no migration in this package**, so
most hosts should define none of them.

`src/config.php` deliberately does **not** carry the sibling's `wpaw_flag()` boolean reader. It is
unused there, and here it would be actively wrong: every host-declared fact in this plugin is a
two-value declaration whose malformed direction has to be explicit, and `(bool)` casting resolves
`'unused'` — the likeliest typo — to `true`. Both readers that do exist are total: an undefined
constant, an array constant and an object constant all yield the neutral default rather than a
warning or a fatal.

## What this does not achieve

**Usernames are not secret in WordPress and this does not make them secret.** It closes five specific
surfaces. Others remain, some of them in core:

- **`/wp-sitemap-users-1.xml`.** Core's own sitemap lists author archive URLs, slug and all — and it
  keeps listing them even with surface 3 declared unused and every archive 404ing. Close it with
  `add_filter('wp_sitemaps_add_provider', fn($p, $n) => 'users' === $n ? false : $p, 10, 2)`.
- **Comment markup.** Core emits `comment-author-<slug>` as a CSS class on every comment by a
  registered user.
- **Feeds.** `<dc:creator>` carries the display name, and an author feed's own URL carries the slug.
- **Body and post classes.** `author-<slug>` appears on author archives and, in many themes, on
  single posts.
- **The lost-password form.** It is a second account-existence oracle and it is not covered here.
- **Plugin output.** Anything that prints a byline, an author box, a schema block or a JSON-LD
  `author` object.
- **Anyone who already has an account.** The REST gate passes anybody with `edit_posts`, which
  includes Contributors.
- **The oEmbed iframe markup**, as opposed to the JSON discovery response. That is theme output.

And the thing that matters more than any of the above: **rate limiting and two-factor authentication
are the controls that actually stop credential stuffing.** A known username plus rate limiting plus
2FA is a far better position than a hidden username with neither. This plugin removes a free
reconnaissance step. Treat it as that, and spend the next hour on the two controls that do the work.

Two smaller consequences worth knowing before you deploy:

- **The flattened login error changes the error *code*, not just the text.** A security plugin that
  keys on `incorrect_password` specifically will stop seeing it. Anything hooking `wp_login_failed` —
  which is most of them, and all the good ones — is unaffected.
- **A headless front end or an integration that reads `/wp/v2/users` anonymously will get a 401.**
  That is the intended behaviour of surface 1 and the reason it is a setting you can untick.

## Tests

```sh
php tests/run.php
```

No WordPress, no database, no network. `tests/bootstrap.php` stubs the small WordPress surface this
plugin touches, and anything it does not define is a fatal error — which is how "loading this plugin
reads nothing" is proved rather than asserted.

One file per decision. Each runs in its **own process**, because the author-archive declaration is a
PHP constant and a constant cannot be redefined: proving both halves of "archives 404 only when
declared unused" needs two processes, and proving that a non-scalar declaration neither obscures nor
raises needs a third.

Every failure mode is asserted in **both** directions. "A malformed setting contributes nothing"
alone passes just as happily against a build where nothing works at all, so each of those assertions
is paired with one proving the mechanism is still live and still refuses when the setting is valid.
Without the pair, the inversion — a plugin that is inert on every surface — keeps the suite green.

The suite was verified RED by breaking each mechanism in turn and confirming the matching file fails
by name: the REST deny, the `edit_posts` allowance, the `/users/me` exemption, the `?author=N` match,
the canonical-redirect suppression, the 404 status, the archive declaration and its exact comparison,
the non-scalar constant guard, the oEmbed `author_url` unset, the login code set, the `'1'`-only
rule, the memoized boot, the no-database-at-load fence, the corrupt-value warning, the toggle
sanitiser, and the read-only rendering of the declaration.
