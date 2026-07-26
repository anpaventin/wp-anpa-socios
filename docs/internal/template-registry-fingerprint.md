# Template registry fingerprint — `canonical-json-v1`

Frozen specification of how `ANPA_Socios_Email_Template_Set::fingerprint()` is computed.

This document exists so the algorithm never has to be reverse-engineered from PHP. If you
are about to change how the digest is produced, you are changing this document and bumping
the scheme — not editing code and hoping the golden file still matches.

**Audience**: developers. This is not board-facing documentation.

## What the fingerprint answers

One question: *is this installation running the template declarations I think it is?*

It is a compatibility contract, not a security mechanism. It is not a MAC, it is not
keyed, and it must never be used to authenticate anything.

## Output format

```
<scheme>:<digest>
canonical-json-v1:3f7a…  (64 lowercase hex characters)
```

The scheme prefix is deliberate. A fingerprint identifies the content **and** how the
content was reduced to a digest. Without the prefix, changing the serialisation would move
every digest without a single declaration having changed, and that reads as two
installations running incompatible registries instead of one scheme superseding another.

- Bump the scheme when **the serialisation** changes.
- Never bump the scheme when **a template** changes.

## Input

A JSON array, in this exact order:

1. The scheme identifier, as a string. It is hashed in as well as prefixed, so two schemes
   can never produce the same digest for inputs that mean different things.
2. One two-element array per event, **in declaration order**: `[event_key, declaration]`.

`declaration` is `ANPA_Socios_Email_Template_Definition::to_array()`, with the alias map
normalised (see Ordering).

### Fields included

Per event, in the order `to_array()` emits them:

| Field | Representation |
|---|---|
| `event_key` | string |
| `display_name` | string |
| `description` | string |
| `category` | string, the `CATEGORY_*` value |
| `audience` | string, the `AUDIENCE_*` value |
| `phase` | string, the phase **identifier** (`Phase::id()`), never its position |
| `retired_in` | string, the phase identifier, or `""` when not retired |
| `default_template` | string |
| `legacy_emitter` | string, `""` when the emitter is not live |
| `variables` | object, canonical token → descriptor |
| `aliases` | object, alias → canonical token |

Each variable descriptor contains `label`, `description`, `example`, `type`, `required`
and `global`, in that order.

### Fields excluded

- Anything derived: `is_live()`, `is_retired()`, `is_emittable()`, `required_tokens()`,
  `sample_data()`, `Phase::position()`. Hashing a derived value adds nothing and would
  make the digest move when the derivation is refactored.
- The variable dictionary as a whole. Only the entries an event actually declares
  participate, through that event's `variables`. A dictionary entry no event uses cannot
  change the fingerprint — and cannot change what anyone sees either.
- The globals list as a list. Globals reach the digest through each event's `variables`,
  where they already appear.
- Anything environmental: site URL, options, locale, plugin version, PHP version.

## Ordering

The rule is one question: **does the order affect what a human sees?**

| Order | Significant | Why |
|---|---|---|
| Events | **Yes**, preserved | It is the order the editor lists templates in |
| Variables within an event | **Yes**, preserved | It is the order the variable panel lists tokens in |
| Aliases within an event | **No**, sorted with `ksort( …, SORT_STRING )` | Aliases never appear in the editor; they are looked up by name when a template is saved |
| Descriptor fields | Fixed by `Variable::descriptor()` | Not data, structure |

Sorting the events or the variables would hide exactly the kind of edit this mechanism
exists to reveal. Leaving the alias order in would make the digest move for a change
nobody can observe.

## Encoding

- `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
  Both flags are pinned so the bytes do not shift if a future PHP changes its default
  escaping, which would otherwise move every fingerprint for no reason.
- Source text is UTF-8. Galician accents are hashed as their UTF-8 bytes, **not** as
  `\uXXXX` escapes — that is what `JSON_UNESCAPED_UNICODE` guarantees.
- Booleans (`required`, `global`) serialise as JSON `true` / `false`, never as `1` / `0`
  or `"1"` / `""`. They come from `Variable::descriptor()` already typed as `bool`.
- Absent values are the empty string, never `null`. `retired_in` and `legacy_emitter` are
  `""` when unset, so a missing value and an empty one are indistinguishable by design.
- Sequential arrays serialise as JSON arrays, string-keyed maps as JSON objects. The
  top-level input is sequential; `variables` and `aliases` are maps.
- Digest: `hash( 'sha256', … )`, lowercase hex.

## Enums and phases

`category`, `audience` and `type` are string constants and are hashed as their string
values. Phases are hashed as `Phase::id()`.

**A phase's position is never hashed.** The delivery order
(`live → 34 → 35 → 36 → 38 → 39 → 37 → 41 → 40`) is a fact about the plan, not about the
declarations. Reordering the plan must not invalidate every registry fingerprint.

## Changing this specification

1. Edit this document first.
2. Bump `ANPA_Socios_Email_Template_Set::FINGERPRINT_SCHEME`.
3. Regenerate the golden digest with the capture flag; never edit the golden file by hand.
4. State in the commit message that the digest moved because of the scheme, not because of
   a declaration change.
