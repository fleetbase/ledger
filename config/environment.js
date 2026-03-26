'use strict';
const { name } = require('../package');

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,
        'ember-leaflet': {
            excludeCSS: true,
            excludeJS: true,
            excludeImages: true,
        },
    };

    return ENV;
};
