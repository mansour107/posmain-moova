# POSMAIN release artifact

Commercial releases are assembled from an exact committed Git tree. The
working directory is never copied, so dirty and untracked files cannot leak
into a deployment.

Preflight:

```sh
php tools/build_release_artifact.php --preflight --ref=<full-commit>
```

Add `--verbose` only when the complete included/excluded inventory is needed.

Build:

```sh
php tools/build_release_artifact.php --ref=<full-commit> --output=/private/release-output
```

The build is fail-closed. It refuses to produce an artifact when:

- a root, `ajax/`, `api/`, `do/`, `get/`, or `print/` PHP entry point is not
  classified in the RBAC manifests or the tiny internal-file allowlist;
- a dependency manifest exists without its lock file;
- the Git tree contains a symlink, submodule, or other unsupported entry.

The policy excludes quarantine-marked routes, repair/debug/setup utilities,
tests, tools, migrations, backups, logs, local output, secrets, database dumps,
office documents, and unknown root files. Runtime pages and routes come from
the RBAC manifests; private source and public assets come from explicit
directory prefixes in `config/release_artifact_policy.php`.

The ZIP embeds `release-manifest.json`. A byte-identical sidecar is written
next to it. The manifest records the source commit and time, policy version,
dependency-lock hashes, and the size and SHA-256 digest of every shipped file.
The artifact must be archived with the release evidence bundle.

This builder does not declare the application production-ready. A successful
artifact build is one prerequisite; financial, inventory, browser, hardware,
backup/restore, and pilot gates remain separate.
