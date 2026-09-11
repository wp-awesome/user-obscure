# user-obscure

Username and account-slug exposure control for WordPress. Five surfaces, three of them simply closed,
two declared in code by somebody who has read the site's templates. **No settings page, and nothing
in a database can change what it does.**

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

Then activate it. That is the whole installation: every surface is closed from the moment it is
active. Two constants exist for the two sites that legitimately need one of them open, and both are
described under [Host constants](#host-constants).

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

| # | Surface | Where it is answered | How it is decided |
| --- | --- | --- | --- |
| 1 | `/wp-json/wp/v2/users` | the route's own `permission_callback`, wrapped via `rest_endpoints` | **constant**, closed by default |
| 2 | `/?author=N` | `parse_request` → `template_redirect` | **derived** from 3 |
| 3 | `/author/<slug>/` | `parse_request` → `template_redirect` | **constant**, open by default |
| 4 | `/wp-json/oembed/1.0/embed` | `oembed_response_data` | always closed |
| 5 | `wp-login.php` error text | `authenticate` | always closed |

### 1. The REST user listing

**The route is not removed.** Unregistering `/wp/v2/users` is the recipe everybody posts and it
breaks the block editor's author panel, several plugins, and anything else that reads the route while
logged in. What is gated here is the *answer*, per request, on capability. Deny is a 401 with core's
own `rest_user_cannot_view` code — not an empty `200`, which is a lie some client will cache.

#### Why this is a permission callback, and must not be "simplified" back to `rest_pre_dispatch`

Until 3.0.0 the decision was a `rest_pre_dispatch` callback. **On a production site running ACF Pro
it was completely inert**, and every check short of fetching the endpoint said it was working.
Measured by the consuming project that found it:

```
callback attached                         prio=10 args=3  (correct)
wpuo_rest_users_pre_dispatch(...) direct  WP_Error(rest_user_cannot_view)
apply_filters('rest_pre_dispatch', ...)   NULL
live GET /wp-json/wp/v2/users             200, all 16 slugs
```

ACF Pro hooks that filter and treats it as an action —
`advanced-custom-fields-pro/includes/rest-api/class-acf-rest-api.php:22`:

```php
add_filter( 'rest_pre_dispatch', array( $this, 'initialize' ), 10, 3 );

public function initialize( $response, $handler, $request ) {
    if ( ! acf_get_setting( 'rest_api_enabled' ) ) { return; }   // bare return, still null
    // ...no return statement on any path
}
```

PHP returns `null` implicitly, so the refusal this plugin had just built was discarded one callback
later. **Turning ACF's REST integration off is not a workaround** — the early return is bare too, so
the disabled path clobbers exactly like the enabled one.

**The hazard is the filter contract, not ACF.** `rest_pre_dispatch` is a filter, and any callback on
it that forgets to return throws away whatever the previous one produced. You cannot audit an
ecosystem for that. A second consuming project went looking in their own plugin inventory and found
the same shape from a different vendor — Gravity Forms,
`class-gf-rest-authentication.php:840`:

```php
return rest_handle_options_request( null, $server, $request );
```

Core returns its *first* argument unchanged when the method is not OPTIONS, and Gravity Forms passes
`null` there rather than the value it was handed. On a GET, that path returns `null` and any prior
`WP_Error` is gone. Narrower than ACF's — it is only reachable for a caller authenticated with a
Gravity Forms API key, so it is not an anonymous exposure — but it is the same discard, reached from
a completely different direction. **Moving off the hook retires that one too, without Gravity Forms
being fixed.**

**This is not "plugins on this filter are dangerous".** The same inventory found WPML's
`replace_shortcode_in_rest_request` on `rest_pre_dispatch` at `PHP_INT_MAX`, and it is *correct*: one
terminal `return $result`, with the body-rewriting branch mutating the request and falling through to
that same return. It is registered for visitors and subscribers, so it is in the chain of an
anonymous request, and an error passed to it comes out the other side intact. The defect is
specifically **a path with no return**.

So the decision moved to the place core provides for deciding whether a request may be answered: the
route's `permission_callback`. **A permission callback has no return value for a careless third party
to discard.** It is asked, per request, and the answer it gives is the answer. Registering later on
the old hook would merely have won a race — one that holds until the next plugin registers at the
same priority, and that is not a property worth depending on.

**The existing callback is wrapped, never replaced.** Core's own check runs first and its refusal is
returned exactly as it gave it, so another plugin's stricter rule on those routes is not loosened by
this one. Only a request that was already going to be permitted reaches the capability gate here.
Both routes the reporter confirmed are covered: the collection, and `/wp/v2/users/<id>`.

One failure mode remains and it is *reported* rather than tolerated: another plugin can **replace**
the permission callback on those routes from a later `rest_endpoints` filter. See
[Checking that it is actually in force](#checking-that-it-is-actually-in-force).

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

One kind of site genuinely needs this surface open: a headless front end, or a JS theme that renders
bylines by fetching `/wp/v2/users` without a cookie. That is a fact about how the site was built, so
it is a declaration in code — `WP_USER_OBSCURE_REST_USERS = 'used'` — and never a checkbox.

### 2. `?author=N` probing

The `301` **is** the leak, not the page it points at. `/?author=1` answering
`301 -> /author/site_admin/` hands over a login slug for an account id before the archive is ever
fetched. Closing it takes two things, and only one of them is the 404: core answers a 404 by trying
`redirect_guess_404_permalink()`, so `redirect_canonical` is suppressed for that request as well.

**This surface has no control of its own, by design.** It is fully determined by surface 3: if author
archives are unused, `?author=N` has nothing to redirect *to* and must 404; if they are used,
redirecting is correct core behaviour and 404ing it breaks a real entry point. See
[The author pair moves together](#the-author-pair-moves-together).

`wp-admin/edit.php?author=5` — how an administrator filters the posts list — is exempt. An `author`
query variable that is not an integer cannot resolve to an account and is left alone; it belongs to
somebody else.

### 3. Author archives

`/author/site_admin/` answering 200 while `/author/nosuchuser/` answers 404 is a valid-username
oracle for anybody who already has a candidate list. Closing it means 404ing a URL shape uniformly,
and **a site that genuinely uses author archives cannot do that** — the pages exist, the theme links
to them, and search engines may have indexed them.

So this one is a **declaration in code**. See [Host constants](#host-constants).

When it is declared unused, a real slug and an invented one get the identical 404. That sameness is
the fix. A 403, or a redirect home, would leave the difference measurable and achieve nothing.

### 4. oEmbed

The surface people forget. It is served by a different controller under a different namespace, so it
survives every `/wp/v2/users` recipe — measured on a site whose REST users route had already been
thought about. `author_name` and `author_url` are **unset**, not blanked: oEmbed makes both optional,
so an absent field is a shape every consumer already handles, while an empty string renders as an
empty byline.

**Always on.** What it costs is a byline in a third party's embed card, which is cosmetic. No site's
correct configuration is "hand the login slug to every consumer that asks", so there is no question
here worth putting to an operator.

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

**Always on.** The cost is real and it is small: somebody who mistypes their *username* is told the
pair is wrong rather than which half of it. The leak it replaces is the sharpest of the five — a
username oracle that needs nothing but the form every site has.

**`wp_login_failed` still fires, still carrying the attempted username.** Rate limiters and
login-attempt logs keep working unchanged. That is deliberate: those are the controls that do the
real work, and this must not damage them to protect a reconnaissance step.

## Why there are no settings

This plugin shipped once with a settings page and four checkboxes. They are gone, and the reasoning
is worth keeping because it is the reasoning that decides what may be added back.

**A toggle nobody should ever flip is an invitation to flip it.** On this plugin every flip was
towards exposure, silently, with no error and no trace — a surface switched off looks exactly like a
surface that was never switched on. Its sibling `site-access` has a settings page because it has
genuine operator modes to choose between, and choosing wrongly there is visible immediately. That
shape was borrowed for this plugin and the reasoning did not come with it. There was one correct
configuration and four ways to degrade it.

**A stale value can no longer silently disable a surface, and that is the defect this removed.** The
four options were read on every request. A restore from an old backup, a hand-edited row, a
migration that copied only some of the table, or simply nobody having ticked the boxes yet, all
produced a site that looked protected and was not. Nothing is read now; there is no value to go
stale.

What survived the cut are the two questions that are genuinely about the site rather than about
preference, and both are answerable only by somebody who has read code:

- Does the theme link to author archives?
- Does the front end read `/wp/v2/users` anonymously?

Those are declared in code, where they are reviewed, versioned and visible in a diff.

**There is no vestigial screen.** Not even one that says "configured in code" — that is a page whose
only function is to disappoint somebody who went looking for a control.

### The author pair moves together

`?author=N` and `/author/<slug>/` used to be two independent controls, and the combination that
shipped as the **default** was incoherent. Measured on a preview site before the toggles were
switched on:

```
?author=1            301 -> /author/site_admin/
/author/site_admin/  404
```

A redirect that hands over the login slug and then points at nothing. That state was reachable
because the two were independently settable, and it was reachable *by default*, which is the worst
case of all — it is the state a site is in when nobody has touched anything.

The probe is now **derived** from the archive declaration. There is no second input, so the broken
pair cannot be expressed. `tests/boot-test.php` asserts that this package has exactly two host
inputs, by name, so a future `WP_USER_OBSCURE_AUTHOR_PROBE` fails the suite rather than quietly
reintroducing the hole. The lockstep itself is asserted in both directions, in each of the four
processes that cover the whole resolution space of the declaration.

## The fail-safe rule: each declaration fails in the direction it can afford

Both constants are read with an **exact comparison against one spelling**, never with a boolean cast,
and the spelling that is matched exactly is chosen per surface.

**Why not a boolean.** `(bool) 'unused'` is `true`, and so is `(bool) 'Unused'`. A boolean-shaped
constant resolves both of the near misses a developer actually makes to whichever direction `true`
happens to mean, and there is no arrangement in which both fall safe. An exact comparison has exactly
one failure direction, which makes the choice of direction an explicit decision rather than an
artefact of PHP's truthiness.

| Declaration | Exact literal | Everything else | Why that way round |
| --- | --- | --- | --- |
| `WP_USER_OBSCURE_AUTHOR_ARCHIVES` | `unused` → 404 the archives | → keep serving them | A wrongly-404ed archive is a published, linked, possibly indexed URL breaking quietly. Nobody notices for weeks. |
| `WP_USER_OBSCURE_REST_USERS` | `used` → answer anonymously | → gate the answer | A wrongly-gated listing is a headless front end failing loudly, in front of the developer who just deployed it. |

So a typo, a wrong case, an array constant, an object constant or a constant nobody defined resolves
to *keep serving author archives* and to *gate the REST user listing*. Neither direction is "off":
each is the failure this particular surface can survive.

**Fail closed by contributing nothing, never by throwing.** Every callback here runs on a site it is
not allowed to take down. No array is cast to a string, no undeclared constant is read, no `$wp_query`
is assumed to have the method it normally has. A malformed anything costs this plugin its effect,
never the site its availability — and never a blanket denial that breaks the editor.

## Checking that it is actually in force

**Registered is not in force, and only one of those is worth knowing.** On the site above, every
check that inspected this plugin said it was working. The callback was attached, at the right
priority, with the right argument count, and calling it directly produced the right `WP_Error`. The
endpoint served sixteen login slugs. Any check that asks *"did we register?"* would have passed that
day, and so would any report built on the answer.

So `wpuo_report()['rest_users']` no longer answers that question. It reads the route table back out
of the REST server, after every plugin's `rest_endpoints` filter has had it, and answers one of:

| value | means |
| --- | --- |
| `in-force` | this plugin's decision is on both users routes, as the server will serve them |
| `not-in-force` | it is not — something replaced the permission callback, and the listing is open |
| `unknown` | there is no REST server in this request, so it cannot be read. **Not a pass.** |
| `declared-used` | `WP_USER_OBSCURE_REST_USERS = 'used'`, so nothing is gated here by design |

It never *builds* a server to find out, because `rest_get_server()` fires `rest_api_init`, and a
report has no business causing that on an ordinary page load. From WP-CLI, build one yourself first:

```sh
wp eval 'rest_get_server(); echo wpuo_report()["rest_users"], "\n";'
```

**Then check it from outside, logged out, which is the only check that proves anything:**

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/wp-json/wp/v2/users
# 401 — anything else, read on
```

**A `200 []` is not evidence of safety.** An unprotected collection returns an empty array when no
user has published content, which is indistinguishable from a working denial. The project that
reported the ACF interaction had exactly that: their preview environment looked clean because no
preview user had published anything, while production — where 4 of 16 users held published posts —
returned all sixteen slugs. **Check on the environment where users actually hold content**, or you
are reading an empty set wearing a denial's clothes.

If you are still on 2.x, this is the check that detects the clobber on that version:

```sh
wp eval '$r=new WP_REST_Request("GET","/wp/v2/users"); echo is_wp_error(apply_filters("rest_pre_dispatch",null,null,$r)) ? "protected" : "CLOBBERED";'
```

**On 3.0.0 that snippet prints `CLOBBERED` on every site, and it means nothing.** This plugin no
longer puts anything on `rest_pre_dispatch`; that line measures a hook it has left. Use the two
checks above instead.

The other report keys are weaker claims and must not be read as this one. `author_archives` and
`author_probe` are **declarations** — what the site said about its own shape. `oembed` and
`login_errors` say only that the callback is attached right now, which catches a `remove_filter()` by
something else and catches a plugin that never booted; neither catches a later callback throwing the
result away, because a filter chain's future behaviour is not inspectable the way a route table is.
Check those two from outside as well:

```sh
curl -s 'https://example.com/wp-json/oembed/1.0/embed?url=https://example.com/' | grep -c author_name
# 0
```

## Upgrading to 3.0.0

**Breaking, and deliberately loud.** Surface 1 moved off `rest_pre_dispatch`, for the reason given
under surface 1 above. Three things change for anything that consumes this plugin:

- **`wpuo_rest_users_pre_dispatch()` is gone.** If you carried
  `remove_filter('rest_pre_dispatch', 'wpuo_rest_users_pre_dispatch')` as a workaround for the ACF
  interaction, **that call is now a silent no-op** — `remove_filter()` returns `false` for a callback
  that was never attached, and says nothing. Delete it. The function is removed rather than left in
  place unhooked, so `function_exists()` answers honestly and nobody can re-attach a decision that no
  longer works. If that workaround was load-bearing on your site, **the listing was open the whole
  time it was in place** — check it from outside before you assume otherwise.
- **`wpuo_boot()['hooks']` lists `rest_endpoints` where it listed `rest_pre_dispatch`.** A test that
  pins that array fails by name, which is the intended way to find out.
- **`wpuo_report()['rest_users']` is a string, not a boolean.** `true`/`false` could not tell a
  registration from an enforcement, and that distinction is the whole of this release. A consumer
  asserting `=== true` now fails loudly instead of continuing to believe a report that was wrong on a
  production site. See [Checking that it is actually in force](#checking-that-it-is-actually-in-force).

`wpuo_report()['oembed']` and `['login_errors']` keep their type. They were the literal `true`; they
now report whether the callback is attached, so they can answer `false` — in a process where this
plugin never booted, they now say so instead of claiming success.

The capability rule is **unchanged**: `list_users` **or** `edit_posts`. Gating on `list_users` alone
would take the block editor's author panel away from every Editor, and that remains the most likely
real-world regression this plugin could cause. The `WP_Error` code and the 401 status are unchanged
too, so anything pinning on `rest_user_cannot_view` keeps working.

## Upgrading from the version with a settings page

**Four options may exist in your database**, named `wp_user_obscure_rest_users`,
`wp_user_obscure_author_probe`, `wp_user_obscure_oembed` and `wp_user_obscure_login_errors`. At least
one production site has all four set to `'1'`.

**They are ignored.** Nothing in this package reads them, in any code path, under any configuration.
Their value does not change what any surface does, and neither does their absence. They are harmless
where they are.

**No migration is written, deliberately.** Code that deletes rows it did not create is a risk taken
for tidiness, and it would have to run on every site to clean up four rows on some of them. If you
want them gone, delete them yourself:

```sh
wp option delete wp_user_obscure_rest_users
wp option delete wp_user_obscure_author_probe
wp option delete wp_user_obscure_oembed
wp option delete wp_user_obscure_login_errors
```

Two other things changed in 2.0.0 for anything that consumes this package. For what changed in
3.0.0, see [Upgrading to 3.0.0](#upgrading-to-300).

- **`wpuo_boot()` no longer returns a `settings` key.** It returns `['hooks' => [...]]` and nothing
  else.
- **`wpuo_report()` kept its five keys and their types.** `author_probe` is `true` if and only if
  `author_archives` is `'unused'`. Two of those types changed again in 3.0.0.

If your site relied on a *ticked* box, you already have that behaviour and more. If it relied on an
*unticked* box, read the table under [The fail-safe rule](#the-fail-safe-rule-each-declaration-fails-in-the-direction-it-can-afford):
the REST user listing is the only one of the four with a way back, and it needs a declaration in
code.

## Host constants

Both optional. A site that defines neither gets author archives served, the REST user listing gated,
and the other three surfaces closed.

| Constant | Default | Purpose |
| --- | --- | --- |
| `WP_USER_OBSCURE_AUTHOR_ARCHIVES` | `used` | `unused` declares that this site does not use author archives, so they — and `?author=N` with them — may 404. |
| `WP_USER_OBSCURE_REST_USERS` | `unused` | `used` declares that this site's front end reads `/wp/v2/users` anonymously, so the listing must be answered. |

```php
// wp-config.php, or a small mu-plugin
const WP_USER_OBSCURE_AUTHOR_ARCHIVES = 'unused';
const WP_USER_OBSCURE_REST_USERS      = 'used';
```

`src/config.php` deliberately does **not** carry the sibling's `wpaw_flag()` boolean reader. It is
unused there, and here it would be actively wrong, for the reason given above. The one reader that
does exist is total: an undefined constant, an array constant and an object constant all yield the
declared default rather than a warning or a fatal.

## There is no environment override

`site-access` shipped `WP_AWESOME_GATE_PRODUCTION_NOOP`, which made its gate inert whenever the
environment was `production`. It was **removed**, for lying to the operator.

Nothing equivalent exists here, and none may be added. There is no screen left to lie to, but the
rule outlives the screen: nothing may make this package inert behind anybody's back.
`tests/boot-test.php` asserts the absence directly — every source file is searched for
`wp_get_environment_type`, `WP_ENVIRONMENT_TYPE` and `NOOP`, and reintroducing any of them fails that
test by name.

## Why this is an ordinary plugin, and its sibling is not

`site-access` must be must-use: it denies a request before WordPress has finished booting, and a gate
that can be switched off from the dashboard it protects is not a gate.

Nothing here has that property. All five surfaces are answered on hooks — `rest_endpoints`,
`parse_request`, `template_redirect`, `oembed_response_data`, `authenticate` — and every one of them
fires long after ordinary plugins have loaded. Must-use placement buys no earlier position.

It would cost something, and the cost points the other way. The realistic failure of this plugin is
that it 401s or 404s a surface some site legitimately uses. An ordinary plugin can be deactivated by
the person who noticed, on the Plugins screen; a must-use plugin needs shell access. When the likely
failure is *"I broke the editor"*, **being deactivatable is the safety property** — and it is the
only off switch this package has, which is deliberate: it is visible, it is logged, and it is
obviously all-or-nothing rather than one surface quietly lapsing.

No stub file is needed, either. WordPress does not recurse into `mu-plugins/` subdirectories — the
trap that makes `site-access` need a top-level stub — but it does recurse into `wp-content/plugins/`,
so a submodule works as a plugin directly.

A host that wants it must-use anyway can `require` the entry point from a top-level mu-plugin stub.
That is supported, and the reason it is supported is worth stating: **nothing here reads a stored
value, calls `current_user_can()`, or consults a filterable value at load.** `wpuo_boot()` only
registers callbacks. Every read happens inside a callback, by which time the whole filter chain
exists.

That matters because the sibling carries a documented residual from the opposite arrangement: it
derives the login path from `wp_login_url()` at mu-plugin load, where a hide-login plugin's filter is
not yet attached, so a relocated login form stays reachable. There is no equivalent here.

```php
$report = wpuo_boot();
// ['hooks' => ['rest_endpoints', 'parse_request', 'oembed_response_data',
//              'authenticate', 'shake_error_codes']]

$state = wpuo_report();
// ['rest_users' => 'in-force'|'not-in-force'|'unknown'|'declared-used',
//  'author_probe' => bool, 'author_archives' => 'used'|'unused',
//  'oembed' => bool, 'login_errors' => bool]
```

The boot report lists **hooks, and nothing else, and a hook is not a control** — a production site
had all five registered correctly while its REST user listing served sixteen login slugs. What is in
force is a different question, and `wpuo_report()` answers it on demand, from inside a request. See
[Checking that it is actually in force](#checking-that-it-is-actually-in-force).

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
- **A headless front end or an integration that reads `/wp/v2/users` anonymously will get a 401**
  until the site declares `WP_USER_OBSCURE_REST_USERS = 'used'`. That is the intended default, and
  the failure is loud enough to find in the first minute.
- **Another plugin can still replace the permission callback on those routes** from its own
  `rest_endpoints` filter, and this plugin would then be inert. That is the one remaining way to lose
  surface 1, it is the reason `wpuo_report()` reads the live route table rather than its own
  registration, and it answers `not-in-force` when it has happened.

## Tests

```sh
php tests/run.php
```

No WordPress, no database, no network. `tests/bootstrap.php` stubs the small WordPress surface this
plugin touches, and anything it does not define is a fatal error — which is how "this plugin reads
nothing" is proved rather than asserted.

**The harness runs the filter chain and dispatches through it**, because the defect 3.0.0 fixes lives
in the chain and not in a callback. A harness whose `add_filter()` only logs can prove that a callback
was attached and that it returns the right thing when called directly — which is exactly the evidence
a production site produced while serving sixteen login slugs.
`tests/rest-users-enforcement-test.php` puts the careless callbacks in the chain by name: ACF Pro's
`initialize`, Gravity Forms' `rest_handle_options_request( null, ... )`, and WPML's
`replace_shortcode_in_rest_request` **because it is correct**, so the file proves the distinction
rather than a superstition about the hook. It asserts the clobber really happens, then asserts the
endpoint is refused anyway. **Against 2.0.0 that file fails**, at the refusal, which is the only
reason it is worth having.

**There is no options table in the harness at all, and that is the central assertion of the suite.**
`get_option()` and `update_option()` throw unconditionally, in every process, for the whole run. A
stored value cannot silently disable a surface if no code path can reach a stored value without
taking the suite down. `tests/boot-test.php` asserts the fence itself throws, so the claim cannot be
quietly hollowed out by softening a stub.

One file per declared value. Each runs in its **own process**, because both declarations are PHP
constants and a constant cannot be redefined: covering the full resolution space of each takes one
process for the exact spelling, one for a near miss, one for a non-scalar value, and one for a site
that declared nothing.

Assertions of an *absence* are always paired with a witness that the package is still live in that
process — usually the oEmbed strip or the login flattening, since nothing can switch those off.
Without the pair, a build that did nothing at all would keep the suite green.

Beyond behaviour, the suite asserts what the package no longer **contains**: no source file names or
reads an option, none registers a screen, the file list is pinned, the retired helper functions are
asserted not to exist, and the set of `WP_USER_OBSCURE_*` inputs is asserted to be exactly the two
constants documented above.

Every mechanism was verified RED by breaking it in turn and confirming the matching file fails by
name: the REST deny, the `edit_posts` allowance, the `/users/me` exemption, the REST declaration's
default direction, its exact comparison, and its resistance to being read as a boolean; the author
pair's lockstep in both the "given its own input" and "hardwired" directions; the archive
declaration's exact comparison, the non-scalar constant guard, the `?author=N` match, the
canonical-redirect suppression, the 404 status, the oEmbed `author_url` unset, the login code set,
the memoized boot, the `authenticate` priority, the report's shape — and, for each of the three
unconditional surfaces, reintroducing a condition, reintroducing a stored value, and reintroducing a
settings screen.

The 3.0.0 mechanisms were swept the same way, each broken in turn against the whole suite: not
registering `rest_endpoints` at all, leaving the single-user route unwrapped, wrapping
`/wp/v2/users/me` which must not be wrapped, replacing the inner permission callback instead of
wrapping it, answering `null` instead of `true` for a permitted request, dropping the `edit_posts`
half of the gate, having the enforcement check report `in-force` unconditionally, having the report
claim a surface without checking it, and putting surface 1 back on `rest_pre_dispatch`. Every one is
caught, by name, by a file that names the mechanism.
