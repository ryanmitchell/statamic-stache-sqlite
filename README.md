# Statamic Stache SQLite

### Purpose
An experiment in replacing the JSON/File stache with SQLite (or MySQL).

Flat files are maintained, so can be Git committed, but you get database performance - the best-of-both worlds.

### Installing
This has intentionally not been added to packagist. 

So install add the following to your composer.json:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ryanmitchell/statamic-stache-sqlite.git"
        }
    ]
```

Then run `composer require thoughtco/statamic-stache-sqlite`.

Once installed, entries, assets and terms will run from an SQLite database in `storage/statamic/cache/stache.sqlite`.

### Configuration

If you want to specify or control the database connection you can specify a connection called `statamic` in your datase config file.

### Commands

`php please statamic:flatfile:warm`

This is the equivalent of stache:warm - it fills your database with all the data from the flatfiles.


`php please statamic:flatfile:clear`

This is the equivalent of stache:clear - it empties your database.


### Feedback

This is an opinionated experiment, so while feedback is welcomed and valued it may be ignored. Please don't be offended if that happens.

This has been made public so others can test it on their Statamic installs to see if performance improvements seen in testing are real.
