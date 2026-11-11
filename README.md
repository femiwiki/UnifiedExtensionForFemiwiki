# UnifiedExtensionForFemiwiki

[![Github checks status]][github checks link]
[![codecov.io status]][codecov.io link]

An all-in-one extension for the various features FemiWiki needs. It provides
the following.

- Asks new users to type the sentence "나는 페미니스트입니다" ("I am a
  feminist") when they sign up
- Adds the terms of use and an infringement report link to the site footer
- Shows links in page content and the sidebar that start with
  `https://femiwiki.com`, or with the same domain depending on the site's
  configuration, as internal links
- Adds the Google Tag Manager script to every page
- Adds the pages a page links to, and the pages linking to it, as its related
  articles
- Gives user emails the FemiWiki theme
- The `{{NUMBEROFEMAILS}}` (`{{이메일수}}`) magic word, which shows the number
  of distinct email addresses. `{{NUMBEROFEMAILS:authenticated}}`
  (`{{이메일수:인증됨}}`) counts only confirmed addresses, and adding `R` shows
  the number without commas. The number of confirmed addresses also appears on
  `[[Special:Statistics]]`.
- Attaches a share card with the title and first paragraph drawn on it as
  og:image to content pages that have no page image
  (`$wgUnifiedExtensionForFemiwikiShareCard`). This needs PHP GD,
  `rsvg-convert` and the fonts in `$wgUnifiedExtensionForFemiwikiShareCardFonts`.

Any release may contain breaking changes. Using it on wikis other than FemiWiki
is not recommended.

## Installation

1. Download the files and put them in a directory called
   `UnifiedExtensionForFemiwiki` in your `extensions/` folder.
2. Add the following to LocalSettings.php.

```php
wfLoadExtension( 'UnifiedExtensionForFemiwiki' );
```

## Contributing

If you are interested in contributing to the code base, please see the document
[How to Contribute].

---

The source code of _femiwiki/UnifiedExtensionForFemiwiki_ is primarily
distributed under the terms of the [GNU Affero General Public License v3.0] or
any later version. See [COPYRIGHT] for details.

[github checks status]: https://badgen.net/github/checks/femiwiki/UnifiedExtensionForFemiwiki
[github checks link]: https://github.com/femiwiki/UnifiedExtensionForFemiwiki/actions
[codecov.io status]: https://badgen.net/codecov/c/github/femiwiki/UnifiedExtensionForFemiwiki
[codecov.io link]: https://codecov.io/gh/femiwiki/UnifiedExtensionForFemiwiki
[how to contribute]: https://github.com/femiwiki/femiwiki/blob/main/how-to-contribute-to-extensions.md
[gnu affero general public license v3.0]: LICENSE
[copyright]: COPYRIGHT
