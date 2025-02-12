This extension provides an API through which users can manage private lists
of articles, such as bookmarks or "Read Later" lists.

The extension is designed for use on wiki farms; it stores articles as
domain name + title pairs, so that the list service can be setup on a single
central wiki.

# Getting started

* Make sure you have installed MediaWiki
* Install ReadingLists using the instructions at https://www.mediawiki.org/wiki/Extension:ReadingLists
* Install BetaFeatures using the instructions at https://www.mediawiki.org/wiki/Extension:BetaFeatures
* Configure the extension locally by editing LocalSettings.php to include the following:
```
$wgReadingListBetaFeature = true;
$wgReadingListsDeveloperMode = true;
$wgReadingListsWebAuthenticatedPreviews = true;
$wgReadingListAndroidAppDownloadLink =  "https://play.google.com/store/apps/details?id=org.wikipedia&referrer=utm_source%3DreadingListsShare";
$wgReadingListiOSAppDownloadLink = "https://itunes.apple.com/app/apple-store/id324715238?pt=208305&ct=readingListsShare";
```
* Navigate to any MediaWiki page and login
* Visit "beta" link and enabling the reading list extension
* If setup correctly you should see a "bookmark" and "Reading lists" link.
