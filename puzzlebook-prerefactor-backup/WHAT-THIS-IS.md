# Pre-refactor backup of puzzlebook's lib/, config/ and bin/

Saved 2026-10-15, immediately before the construction refactor that moved each
rule's build logic into its own class.

**This is the version Ian confirmed working** — the one that built a 9-page book
revealing "eliminate any word knight chess move from surviving five letter words"
and ending on `dragon`. It was never committed to git, so this folder was the only
copy; it originally lived in an ephemeral `/private/tmp` scratchpad and was moved
here so it would survive a reboot.

## To restore it

```
cd /Users/ian/Documents/Repos/github/ianring/lab
cp -R puzzlebook-prerefactor-backup/lib puzzlebook-prerefactor-backup/config \
      puzzlebook-prerefactor-backup/bin puzzlebook/
```

Note that the backup does **not** include `data/wordlist.txt`, `web/`, `README.md`
or `build/` — none of those were touched by the refactor, so the live copies are
fine.

## Delete this folder once puzzlebook is committed to git

It exists only because `puzzlebook/` was untracked (`?? puzzlebook/`) and therefore
had no restore point. Once there's a commit, git is the better backup and this is
just clutter:

```
rm -rf puzzlebook-prerefactor-backup
```
