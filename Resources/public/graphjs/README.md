# Vendored: @glitchr/graphjs lanes

`lanes.js`, `lanes-view.js` and `lanes.css` are the files of [@glitchr/graphjs](https://github.com/glitchr-studio/graphjs)
1.1.0 (commit 2b6ccf3), byte for byte: the `Lanes` class and its layout, which draw the commit graph's
lanes beside its rows (`templates/_graph.html.twig`). The bundle has no build step; `assets:install`
publishes them under `bundles/git/graphjs/`. `lanes-view.js` imports `./lanes.js`: the two stay side by
side under these names.

To update after a graphjs release:

    cp <graphjs-repo>/src/js/lanes.js       Resources/public/graphjs/lanes.js
    cp <graphjs-repo>/src/js/lanes-view.js  Resources/public/graphjs/lanes-view.js
    cp <graphjs-repo>/src/css/lanes.css     Resources/public/graphjs/lanes.css

A fix goes into graphjs first, then is copied here.
