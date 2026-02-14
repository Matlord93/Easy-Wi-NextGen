(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EasyWiGameserver = root.EasyWiGameserver || {};
        root.EasyWiGameserver.domMount = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const mount = (selector) => document.querySelector(selector);

    const requiredDataset = (element, keys) => {
        const missing = keys.filter((key) => !element.dataset[key]);
        return {
            ok: missing.length === 0,
            missing,
        };
    };

    return {
        mount,
        requiredDataset,
    };
});
