# Phabricator tasks:

- [T402231](https://phabricator.wikimedia.org/T402231): Create hidden user preference to scope Reading Lists web UI to specific logged-in users
- [T405294](https://phabricator.wikimedia.org/T405294): Make ReadingLists available on web for users in experiment (with hidden preference)

# Status

Status: Accepted

Date: Oct 23 2025

# Problem statement

How do we roll out the ReadingLists saved pages "boomkark" feature on web for desktop users as an experiment?

We want to bring the ReadingLists feature that currently exists on the mobile apps to web users. This would allow users to save pages to a list.

For readers, we are placing a button in the vector-page-toolbar where the watchstar is currently. This would make it easy to save pages to their reading list. We plan to gradually roll out this feature via Test Kitchen to logged in users on desktop (Vector 2022 skin) who:

- Have 0 edits
- Not using their watchlist, with no article namespace pages in their watchlist.
- Not using ReadingLists on the mobile apps, determined by not having any Reading Lists created yet.

The Reading Lists bookmark feature is also available via BetaFeatures on test.wikipedia and the beta cluster.

# Decision Outcome

We decided to compile a list of eligible users via analytics queries for the wikis that will be part of the experiment.  The Wikipedia sites planned for the experiment include Arabic (ar), French (fr), Vietnamese (vi), Chinese (zh), Indonesian (id) and English (en).

We created a maintenance script in the extension to set the hidden preference `readinglists-web-ui-enabled`:

-  `maintenance/setReadingListHiddenPreference.php` (NOTE: the script will be removed at a later time when the experiment is done)

In `HookHandler.php`, we defined a hook handler for the `onSkinTemplateNavigation__Universal` hook.

In the hook handler, we have a check to determine if the Reading Lists bookmark feature is available for a user.  The code checks if either:

- The user has Reading Lists enabled as a beta feature.
- OR:
  - The user has the hidden preference `readinglists-web-ui-enabled` set.
  - AND the experiment is enabled.
  - AND the user is in the experiment treatment group.

After the experiment, we will cleanup the hidden preference for users that did not save any pages to their Reading List.  Users who saved pages will retain the feature and eventually we plan to roll it out as a Beta Feature once we refine the feature and user experience more.

As we rollout the experiment to a smaller set of users, we can evaluate the performance impact and improve the scalability of the feature and ensure optimal database performance and caching is put into place.

# Decision Drivers

We want to limit the amount of user preferences that are set with this experiment, due to the fact that the `user_properties` database table is already very large.  With compiling a list of eligible users, we limit the number of users who can be in the experiment and have the hidden preference.

Doing all the eligibility checks on the fly also can have a performance impact. The database tables for the ReadingLists extension are on the `x1` database cluster, which is shared storage across all wikis.  `x1` has a limited amount of replicas so we also need to be mindful of how many queries we do against the reading_lists tables.

The watchlist tables are also sharded so also need to be queried separately.  We also want to avoid doing database writes when a user is viewing a page.

With using the hidden preference, we avoid write queries and expensive database queries when a user views a page and limit the experiment to some maximum number of users.