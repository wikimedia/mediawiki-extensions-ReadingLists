/**
 * @typedef {Object} Collection
 * @property {number} id
 * @property {boolean} [default]
 */

/**
 * @typedef {Collection & { withTitle: boolean }} CollectionWithTitle
 */

/**
 * @typedef {Object} CollectionItemLocal
 * @property {number} id
 * @property {string} title
 * @property {number} [pageid]
 */

/**
 * @typedef {CollectionItemLocal & { project: string }} CollectionItem
 */

/**
 * @typedef {Object} CollectionCardThumbnail
 */

/**
 * @typedef {Object} CollectionCard
 * @property {number} id
 * @property {string} title
 * @property {string} [redirectTitle]
 * @property {string|null} [description]
 * @property {string} [project]
 * @property {boolean} [missing]
 * @property {CollectionCardThumbnail|null} thumbnail
 */

/**
 * @typedef {Object} BookmarkPopoverResult
 * @property {boolean} isSaved Whether the page remains saved in any reading list
 * @property {boolean} showNotification Whether to show an mw.notification. Only needed if
 *                                      a page is saved to a custom list or unsaved.
 * @property {boolean} [isError] Did an error occur?
 * @property {boolean} [isRemoved] Whether the action removed the page from a list.
 *                               Defaults to !isSaved for removal from all lists.
 * @property {number} [listId] ID of the collection the page was added to or removed from, if any.
 * @property {string} [listName] Name of that collection, if any.
 */

module.exports = {};
