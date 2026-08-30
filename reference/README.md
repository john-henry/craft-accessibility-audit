# Internal reference

Working notes for building the plugin. Not user documentation, and deliberately outside `docs/`: VitePress builds every markdown file in that tree, so anything placed there is published to the docs site and copied on to johnhenry.ie.

Nothing in here is published, and nothing in here should be pasted anywhere.

| File | What it is |
|------|------------|
| `vpat-remark-patterns.md` | How published VPATs write their Remarks and Explanations, distilled into patterns. Feeds the drafting prompt in `VpatService::draftRemark()`. Built by the `vpat-corpus` skill. |
| `vpat-corpus-sources.md` | Every VPAT read while building the above: vendor, product, edition, date, URL. Provenance for the claims in the pattern guide. |

Both are generated and maintained by the `vpat-corpus` skill. Read that skill before editing either by hand.

**Copyright:** the documents behind this analysis belong to their vendors. No VPAT text is stored here, and none should be. The pattern guide describes a genre in its own words; if a passage in it could be mistaken for an excerpt, it needs rewriting.
