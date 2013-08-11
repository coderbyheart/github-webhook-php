# GitHub WebHook written in PHP

This is a simple webhook for GitHub written in PHP which can be used to update a local working copy of a repository and
supports the execution of a deploy script.

## Configuration

The mapping between remote repository and local copy is configured in the `config.ini`.

Recipients for notification emails can be configured there, too.

## Simple deployment support

The hook also looks for a file named `deploy/<hostname>.sh` in the working copy and executes it if present.
