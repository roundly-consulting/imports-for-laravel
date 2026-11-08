# Changelog

All notable changes to `imports-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Importers: one class per record type (`Importer`) with typed fields (text, integer, decimal, boolean, date, date-time, email, choice, reference), labels in any locale, aliases, examples, normalisers and validation rules; an ordered natural key for duplicates; owner-scoped reference lookups; create, update, authorize and rollback callbacks. `Imports::importers()` describes them for a UI.
- Templates: `Imports::template()` writes a CSV (with a hint row) or an XLSX (bold frozen header, column formats, dropdowns for choice and yes/no columns, an input message per column) with headers in English or Slovak.
- Dependency-free streaming readers for CSV (UTF-8 with or without BOM, comma or semicolon sniffed or chosen, quoted fields, CR-only files, Excel's `sep=` line) and XLSX (shared strings spooled to disk, every cell type, 1900 and 1904 dates, any sheet), available on their own through `Imports::read()`. Other encodings and formats are refused with a message that says what to do.
- Limits on file size, rows and columns; ZIP and XML hardening (consistency checks, real inflated-byte caps, no DOCTYPE, no entity expansion).
- Column mapping suggested from headers (names, labels in both locales, aliases, fuzzy matches flagged for review), changeable by header or column number, and saved per owner through options-for-laravel.
- A preview stored as a resumable session: every row validated (required fields, formats, allowed values, references, duplicates in the file and in the database), errors per row and cell, counts, update diffs, an error report to fix and re-upload. Large files validate on the queue with progress.
- Duplicate modes — skip, update or create new — re-planned without re-reading the file.
- Commit as one Laravel job batch of chunks, each chunk in one transaction with its ledger rows, so a retried or redelivered chunk never writes twice, and a chunk of a batch that is no longer running (failed, abandoned, cancelled) writes nothing; a stopped batch's remaining jobs are cancelled, and a queue that refuses the dispatch fails the commit at once (`dispatch_failed`) and frees the lock; cancel and resume; `ImportStarted`, `ImportChunkProcessed`, `ImportCompleted` and `ImportFailed` events carrying who, the owner, the counts and the file.
- Undo within a window: created records unchanged since the import (checksum or `updated_at`) are deleted or handed to the importer's rollback hook; changed ones are kept and listed; updates are never reverted.
- Owner context as a security boundary: every lookup and write stays inside the import's owner, with a pluggable `OwnerScope` (a teams-for-laravel recipe in the docs) and one running import per owner and importer. Every read of a session or batch (status, headers, counts, progress, summary, records, the session list) asks the importer's `authorize()` for `View`, once per handle; a missing batch is reported as a batch.
- File batches (`FileImporter`): many files or a ZIP, one record per file, SHA-256 duplicate checks, hardened extraction (file count, size, compression ratio, paths, symlinks, encryption).
- An opt-in JSON API (`imports.routes.enabled`) with FormRequests and resources that never expose class names or file paths; swappable `imports.resources.owner|actor|record`. `GET {prefix}/sessions?importer=&status[]=&per_page=` lists the owner's sessions the user may view, so a client can come back to an open preview.
- `imports:sweep`, `imports:prune`, `imports:doctor`, `imports:importers`, `imports:template` and `imports:install` commands; `about` section. `imports:install` also publishes options-for-laravel's migration (saved mappings live there) unless its table exists, `imports:doctor` checks that table, and a missing one fails with the command that fixes it.
- `Imports::fake()` with assertions, and `SpreadsheetFactory` to build CSV, XLSX and ZIP uploads in tests.
- English and Slovak translations.
