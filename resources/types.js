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
 * @typedef {Object} DismissData
 * @property {boolean} isSaved Whether the page has been saved to or unsaved from a list
 * @property {boolean} showNotification Whether to show an mw.notification. Only needed if
 *                                      a page is saved to a custom list or unsaved.
 * @property {boolean} [isError] Did an error occur?
 * @property {string} [listId] ID of the list the page was saved to, if any.
 * @property {string} [listName] Name of the list the page was saved to, if any.
 */

module.exports = {};
