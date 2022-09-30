const Api = function () {};
function Title( path ) {
	this.path = path;
};
Title.prototype.getUrl = function () {
	return `/wiki/${this.path}`;
};

global.mw = {
	msg: () => '{msg}',
	Api, Title
};
