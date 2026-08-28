# Filesystem - Author Restriction (`plg_filesystem_authorrestriction`)

A **Joomla 5** plugin that confines the Media Manager — both the standalone
**System → Media** screen and the Media field picker inside **Articles** — to
one user's own folder (named after their numeric user ID, e.g. `/123`).
**Super Users** and members of the **Administrator** group are left untouched
and keep the full media tree. Built for 
the 

## Why a plugin, and why this shape

Joomla's Media Manager (`com_media`) has **no folder-level ACL**. Its
`access.xml` only defines component-wide actions (`core.create`,
`core.delete`, `core.edit`, `core.manage`) — confirmed by
`administrator/components/com_media/src/Controller/ApiController.php`, which
only ever checks e.g. `authorise('core.delete', 'com_media')`, blind to which
path is being touched. There's also no per-request "about to serve this path"
event to hook into.

What Joomla *does* have is a **`filesystem` plugin group**: every time
com_media needs an adapter, it fires an `onSetupProviders` event to that
group (see `Provider/ProviderManagerHelperTrait.php`), and whichever plugins
are subscribed hand back `ProviderInterface`/`AdapterInterface` implementations.
On the site, only the core **"Filesystem - Local"** plugin
(`plg_filesystem_local`) is registered, serving one adapter rooted at
`data/` (its own `directories` param — *not* `com_media`'s `file_path`/
`image_path` fields, which are legacy/cosmetic and unused by the actual
folder resolution).

So this plugin sits in the **same group**, installed to run **after**
`plg_filesystem_local` (new plugins are appended to the end of their group's
ordering). On `onSetupProviders` it:

1. Resolves whether the current user should be restricted (see below).
2. If not (Super User / Administrator / guest/no session) — does nothing;
   every provider is left exactly as the core plugin registered it.
3. If restricted — unregisters every already-registered provider and
   re-registers a **decorator** under the same provider ID
   (`RestrictedProviderDecorator`), whose adapters are themselves decorators
   (`RestrictedAdapterDecorator`) around the real ones.

The adapter decorator implements `AdapterInterface` by **presenting the
user's folder as its own root**, translating every path in both directions:
a caller-supplied path (always relative to "/") is turned into a real path
by normalizing it and prefixing the user's real folder before calling the
wrapped adapter, and every path the wrapped adapter hands back (in
`getFile`/`getFiles`/`search` results, and the `move`/`copy` return value) is
translated back the other way before it leaves this class. This isn't
optional polish — `AdapterInterface` documents `path` as "the relative path
to the root", and the Media Manager UI's own state tracks "what directory am
I browsing" from the paths it requested; an earlier version of this class
only did the forward translation (redirecting the request) and returned the
wrapped adapter's real, `data/`-rooted paths unchanged, which the UI's client
state didn't match — the folder opened but silently rendered no files. A
request for the root (`/`) resolves to the user's own folder, so Media
Manager opens straight into it — it looks like their folder *is* the root,
matching "an author only sees their own folder" literally, including for the
shared/legacy assets that also live loose in `data/` (`business/`, `certs/`,
`documents/`, `logos/`, …) — those become unreachable to a restricted user by
design, not just the other users' ID folders. Because every real path this
class ever passes to the wrapped adapter is *computed* (virtual path,
normalized, then prefixed) rather than taken from caller input and merely
checked, there's no separate boundary check to bypass — a virtual path can
only ever resolve under the user's own real folder.

Because every entry point (standalone Media Manager, the Articles Media
field, the classic AJAX task-based calls, the JSON `ApiController`) resolves
adapters through the exact same `ProviderManager → getAdapter()` call, one
plugin covers all of them with no duplicated logic. No core file is touched,
so it survives Joomla core/extension upgrades; disabling the plugin fully and
instantly restores stock behaviour.

## Who gets restricted

A user is **unrestricted** (full tree) when either is true:
- `$user->authorise('core.admin')` is true (Super User), or
- they are a **direct** member of the `Administrator` or `Super Users`
  group (looked up by group **title**, not a hardcoded group ID, in case
  the site's group tree is ever edited).

Everyone else who reaches the Media Manager (in practice, the `Manager`
group) is confined to `/<their user id>`.

If a user doesn't have their folder yet (a brand-new Manager, or the one
pre-existing gap found during an earlier audit —
isn't actually an Author so it's harmless there), the plugin creates it
on first use — best-effort, so a failure just surfaces normally on the next
real file operation instead of silently succeeding.

## Requirements

- Joomla **5.x**, PHP **8.1+**
- The core **"Filesystem - Local"** plugin enabled (it is, by default).

## Installation

**Packaged zip:** zip the repo contents (manifest `authorrestriction.xml` at
the zip root) → Joomla admin **System → Install → Upload Package File**.

**From source (dev):** copy this repo's contents into
`<joomla>/plugins/filesystem/authorrestriction/`, then install via
**System → Manage → Discover**.

After installing:
1. **Enable** it in **System → Manage → Plugins** (search "Author
   Restriction").
2. Confirm its **ordering** is *after* "Filesystem - Local" in the
   `Filesystem` group list (Joomla appends new plugins to the end, so this
   should already be the case — reorder manually if not).
3. **If installed via CLI `extension:discover:install`** (not the web UI, and
   not a package upload): that command does **not** fire Joomla's
   `onExtensionAfterInstall` event, so the core "Extension - Namespace Map"
   plugin never regenerates `administrator/cache/autoload_psr4.php` — the
   result is a PHP `Class "...\AuthorRestriction" not found` error the first
   time *anything* touches Media (e.g. opening the Articles Media field),
   for every user, not just restricted ones. Fix: rebuild that cache file —
   either trigger any other extension's real install/update/uninstall
   through the web UI (regeneration rescans every extension on disk), or
   instantiate `JNamespacePsr4Map` (`libraries/namespacemap.php`) directly
   and call `->create()` as the site user (`opensaiwww`), so the regenerated
   file keeps the right owner. **Always check for this after a CLI-based
   discover-install of any custom extension on the site**, not just this one.

## Testing checklist

- Log in as a `Manager`-group user (not Super User/Administrator): System →
  Media opens directly into `/<their id>`; browsing "up" or editing the URL
  path is rejected like a missing file; the same is true from an article's
  Media field.
- Log in as **Super User** or **Administrator**: Media Manager shows the
  full `data/` tree exactly as before, including other users' folders and
  the shared `business/`/`certs/`/`documents/`/etc. folders.
- Disable the plugin: behaviour reverts instantly to stock Joomla for
  everyone (no leftover state anywhere else).

## License

GNU General Public License version 2 or later — see `LICENSE`. (Matches
Joomla core's own license, since this plugin decorates core interfaces
directly.)
