# bhl-wikidata

Generate quickstatements for BHL content.

Enter one or more identifiers, one per line:

- a DOI, e.g. `10.24199/j.mmv.2004.61.3`
- a BioStor id, e.g. `biostor:192990`
- a BHL part id, e.g. `bhlpart:202055`

The tool checks whether Wikidata already has the work, by identifier and by metadata. If it
doesn't, you get Quickstatements to create it. If it does but is missing an identifier we
know about, you get Quickstatements to add just that identifier.

There is also `cites.php`, which adds citation links to works with DOIs.

## Keeping the author cache warm

`creators.json` maps BHL creator ids to Wikidata items, so authors can be linked with `P50`
rather than recorded as a name string. Wikidata holds fewer than 60,000 of these, so the
whole lot is fetched in one go and committed rather than discovered a few at a time while
somebody waits for a page to load:

    php update-creators.php

While that file is less than 30 days old the tool answers author lookups from it alone and
makes no query at all. After that it falls back to asking Wikidata, so a forgotten rebuild
gets slower rather than quietly reporting recently linked authors as unlinked. Re-run it
whenever you want to pick up newly linked authors, and commit the result.
