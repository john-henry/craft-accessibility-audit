# How VPAT remarks are actually written

Distilled from 37 published conformance reports and nine guidance sources, all listed in `vpat-corpus-sources.md`. Frequencies are stated against those 37 and nothing wider. Read the limits section of the sources file before treating any of this as a general truth about VPATs.

Built 30 August 2026 by the `vpat-corpus` skill.

---

## 1. What a remark is for

A conformance level is a verdict with no evidence attached. The remark is where a buyer finds out whether to believe it. Its reader is a procurement officer holding several competing reports, deciding which product will cost them least trouble, and unable to install anything to check. Everything below follows from that: they need to know what fails, where, how much of it, and whether anyone is doing anything about it.

The corpus divides sharply on whether authors understand this. Reports that name components and point at tracked issues read as written by a team that has looked at its own product. Reports that give a level and a blank column, or a level and a number, read as compliance paperwork.

---

## 2. What the levels actually get

### Supports

**No consensus, and the range is total.** Remark coverage on Supports rows runs from nothing at all (GitHub Docs: fifty criteria, fifty Supports, an entirely empty remarks column; Plone: two remarks across sixty-one criteria; Roberts Wesleyan leaves most blank) to every row (the two GSA reports).

The standard explains it: Section508.gov requires remarks only for Partially Supports and Does Not Support, and calls them encouraged but not required otherwise. A blank Supports column is conformant, and roughly half the corpus leaves it blank.

Where Supports rows do carry remarks, four things happen and only the first two are worth imitating:

- **Evidence.** What was tested and what was found. Rare.
- **Named capability.** D2L lists the actual features that satisfy the criterion, and quantifies where it can, claiming a 7:1 ratio rather than the required 4.5:1. Concrete and checkable.
- **Criterion restatement.** Glean's report, prepared by Level Access, answers 1.4.3 by restating the 4.5:1 threshold back at the reader. Digital Theatre asserts sufficient contrast "in all places". These say nothing the level had not already said.
- **Design intent.** Both GSA reports lead on what the product was designed to do rather than what testing showed. It reads well and establishes nothing: an intention is not a behaviour.

### Partially Supports

The hardest row, the most common on a real report, and where the corpus separates most. Three independent guidance sources converge on the same three required elements: the specific issue, where it occurs, and the user impact. Target length two to four sentences.

Practice splits into four approaches, in rough order of usefulness:

**Exception-framed, scoped and located.** The strongest pattern in the corpus, and notably it is the accessibility vendors who use it. Deque states what passes, then names the exception, then the user impact, then the exact page it occurs on. Clarivate does the same and adds a target date and a link to a live known-issues portal. Atlassian leads with a stem sentence and follows it with a bulleted list of named elements: placeholder text, breadcrumb hover text, a specific card and its focus state. The reader finishes knowing what to go and look at.

**Located by path.** SciTech writes its locations as a breadcrumb: page, then section, then element. The most precise scoping form in the corpus and the easiest to act on.

**Named, not counted.** Dropbox, Moodle, Plone and GovReady name what is broken and never say how much of it there is. Severity is left to the reader.

**Counted, not named.** All three GitHub reports use one template on every Partially Supports row: an exceptions preamble followed by issue counts split into high, medium and low priority. Thirty-two criteria on GitHub.com, identical construction on every one. It is precise and uncheckable: a criterion carrying 351 low-priority issues tells a buyer nothing about what they are, which pages hold them, or whether any of it matters to them.

**Scope and count are separate axes, and almost nobody does both.** That remains the sharpest finding. A count with no subject is a statistic; a subject with no count leaves severity unknown. Only Trello and Taylor & Francis combine them, and both dilute the effect by padding the remark with the criterion's own rationale.

### Does Not Support

Uncommon, and heavily concentrated: Atlassian's Jira Service Management carries 27 and Elsevier's ScienceDirect 61, while most documents have none or one. Where it appears, remarks are short and unhedged, and the terseness reads as candour. Guidance asks for user impact to be spelled out; the corpus mostly names the missing capability and stops.

### Not Applicable

One sentence saying why the criterion cannot apply. Heavily boilerplated, reasonably so: a product with no video says it the same way each time. Two things to avoid. The ACR Editor leaves twenty-three Not Applicable rows unexplained, and inapplicability is a claim like any other. And Project MUSE and SciTech mark over 200 rows Not Applicable each, which past a certain volume starts to read as scope being defined to avoid work rather than to describe a product.

---

## 3. Conventions the corpus broadly agrees on

**Test method belongs to the report, not the row.** 36 of 37 keep it out of the remarks entirely and put it in one evaluation-methods section: tools, assistive technologies, whether testing was manual or automated. Repeating it per criterion would bloat fifty rows to say one thing.

The exception is instructive. D2L appends a code list to each row, `Test Methods: TBT; CCT; SMT; UBT`. It is compact and, once the reader learns the abbreviations, tells them which criteria got user testing rather than only a tool. It works because it is codes rather than prose. Anything longer would not.

**Remarks do not restate the criterion.** Near-universal, and guidance warns against it explicitly. Authors assume the reader has the criterion in the adjacent column, which they do. The exceptions are Taylor & Francis, which reproduces the full WCAG requirement before saying anything about the product, and Glean.

**Scope of evaluation is rare and valuable.** Only Deque, Atlassian and Clarivate list which pages and components were actually audited. Deque names eleven pages and nine components. Without it, "Supports" covers an unknown surface, and this is the single cheapest thing a report can add to be trusted.

---

## 4. Remediation: the guidance is mostly ignored, and the exceptions are the interesting part

Every guidance source asks for a fix commitment or target date on Partially Supports rows. **32 of 37 documents give none.** Four distinct approaches exist among those that do:

- **Dated, per criterion.** ProQuest names a quarter in the remark itself. But the same sentence, and the same date, is pasted across many criteria, so it functions as a policy statement rather than a per-criterion commitment.
- **Dated, plus a live tracker.** Clarivate gives a half-year target and links a known-issues portal. The strongest form in the corpus: a date the reader can check against something.
- **A separate roadmap document.** Third Iron publishes a companion "VPAT Remediation Timeline" repeating the criteria rows with a half-year target added to each. Keeping dates out of the ACR and in a document that can be revised on its own is a sound instinct.
- **Committing to re-test rather than to fix.** Clarivate also states which pages are excluded from this audit and when they will be assessed. Honest, and easier to keep.

**The risk is visible in the corpus too.** Adam Matthew's Quartex report promises resolution "during 2022 and 2023" in a document dated October 2023. The window had closed before the report was published. A date makes a report better only while it is true, which is why a live tracker beats a date, and a date beats nothing.

**Elsevier does something no one else does**: a revision history at the front listing what changed since the last version, per criterion, naming which were upgraded to Supports and what was fixed. It turns the ACR into a record of progress rather than a snapshot, which is exactly the criticism Homer Gaines levels at reports treated as static scorecards.

---

## 5. What weak remarks do

Named here so the plugin's drafting can be steered off them.

**Hedging that carries no information.** The commonest failure, and it is worst in the largest documents: Atlassian's two Jira reports carry the heaviest hedge counts in the corpus. *Generally*, *some*, *most*, *usually*, *minor*, *often*. The tell is a hedge with no number and no component beside it. "Some images" without a count or a location leaves the reader where they started.

**Support claimed with nothing behind it.** A Supports row with no remark, no method, no scope. Common, and conformant to the standard, which is why it persists.

**Counts standing in for description.** The GitHub pattern. Worth naming separately from hedging because it looks like the opposite of vagueness and functions the same way.

**Boilerplate across unrelated criteria.** Fine for Not Applicable rows. Not fine on Partially Supports, where GitHub uses identical construction on thirty-two different criteria and ProQuest repeats one roadmap sentence across at least six. Identical text on unrelated criteria means no criterion was considered on its own terms.

**Design intent instead of findings.** Both GSA reports. Designed-to phrasing is not testable.

**Mitigation claimed instead of a fix.** SciTech marks contrast failures and appends that they are mitigated by an accessibility solution, meaning an overlay widget. A buyer reads that as the failure standing and a script being asked to cover it.

**Responsibility shifted downstream.** The pattern closest to home. Highcharts frames its whole report around conformance being contingent on the implementing developer, which qualifies every claim in the document without any single row appearing to hedge. This is the trap a component or plugin vendor falls into, and it contains a real truth: a library's output does depend on how it is used. The line to hold is that the component's own behaviour is testable and claimable, and only the integration belongs to the customer. Deque, whose product is also a plugin, shows the alternative: it names its own pages and components and claims them.

**Marketing register.** Named by guidance, largely absent from the corpus.

---

## 6. Register

- **One to three sentences.** Two to four for Partially Supports. Nothing in the corpus is long.
- **Bulleted exception lists are standard**, not a deviation. Most of the stronger reports use a stem sentence followed by bullets.
- **Product named or referred to plainly.** No first person, except in the remediation sentences, where "we" appears.
- **Present tense for current behaviour.**
- **Technical, but not to specification level.** Component names, page areas and behaviours appear; WCAG technique numbers do not, with one exception: Roberts Wesleyan cites sufficient-technique IDs such as G125 and C32 as its remarks, which is compact and unreadable unless you know the codes by heart.
- **Passive voice** is normal for findings and reads as neutral. It reads as evasion when it removes the actor from a commitment.
- **EN 301 549 mapping.** Every INT and EU edition report lists the corresponding 9.x, 10.x and 11.x clauses beside each WCAG criterion. That mapping is the template's work, not the remark's, and no remark in the corpus discusses EN 301 549 in prose.

---

## 7. Where this plugin departs from the corpus, on purpose

Two of the plugin's rules cut against common practice. The corpus describes what vendors do, which is not the same as what a tool generating these documents on someone's behalf should do.

**The draft never states or suggests a conformance level.** Many published remarks assert the level in the first clause. When a person writes that, they are standing over a judgement they made. When a tool writes it, it is manufacturing a judgement nobody made and putting it in a document that carries legal weight.

**A clean scan is not grounds for a draft.** The corpus is full of Supports rows resting on nothing, and the plugin could generate more of them trivially. It does not: no criterion in this plugin is settled end to end by automated testing, so an absence of findings is evidence towards an answer and never the answer.

**Where the plugin can beat the corpus.** It holds both axes that almost nobody combines. Every finding carries its rule, its page and its occurrence count, so a drafted remark can name what fails *and* say how much of it there is. It can follow the traceability convention too: Drupal links issue IDs, Clarivate links a known-issues portal, and the plugin's equivalent is the page report behind each finding. And its evidence lines already state what was checked and what could not be, which is the scope-of-evaluation section that only three of thirty-seven documents bother to include.
