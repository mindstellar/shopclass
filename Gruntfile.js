module.exports = function (grunt) {
    'use strict';

    // Define directories
    var assetsDir = 'oc-includes/assets';
    var nodeDir = 'node_modules';

    // Load all grunt tasks
    require('load-grunt-tasks')(grunt);

    // Project configuration
    grunt.initConfig({
        clean: {
            vendors: [assetsDir, 'oc-admin/themes/modern/scss/bootstrap']
        },
        copy: {
            'jquery': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/jquery/dist/jquery.min.js',
                        nodeDir + '/jquery/LICENSE.txt'
                    ],
                    dest: assetsDir + '/jquery',
                    flatten: true
                }]
            },
            'jquery-migrate': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/jquery-migrate/dist/jquery-migrate.min.js',
                        nodeDir + '/jquery-migrate/LICENSE.txt'
                    ],
                    dest: assetsDir + '/jquery-migrate',
                    flatten: true
                }]
            },
            'jquery-ui': {
                files: [
                    {
                        expand: true,
                        src: [
                            nodeDir + '/jquery-ui-dist/*.min.js',
                            nodeDir + '/jquery-ui-dist/*.min.css',
                            nodeDir + '/jquery-ui-dist/LICENSE.txt'
                        ],
                        dest: assetsDir + '/jquery-ui',
                        flatten: true
                    },
                    {
                        expand: true, src: nodeDir + '/jquery-ui-dist/images/*',
                        dest: assetsDir + '/jquery-ui/images',
                        flatten: true
                    }]
            },
            'jquery-treeview': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/jquery-treeview/jquery.treeview.js',
                    ],
                    dest: assetsDir + '/jquery-treeview',
                    flatten: true
                }]
            },
            'jquery-validation': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/jquery-validation/dist/jquery.validate.min.js',
                        nodeDir + '/jquery-validation/dist/additional-methods.min.js',
                        nodeDir + '/jquery-validation/LICENSE.md'
                    ],
                    dest: assetsDir + '/jquery-validation',
                    flatten: true
                }]
            },
            'spectrum-colorpicker': {
                files: [
                    {
                        expand: true,
                        src: [
                            nodeDir + '/spectrum-colorpicker/spectrum.js',
                            nodeDir + '/spectrum-colorpicker/LICENSE',
                            nodeDir + '/spectrum-colorpicker/spectrum.css'
                        ],
                        dest: assetsDir + '/spectrum-colorpicker',
                        flatten: true
                    }]
            },
            'bootstrap-icons': {
                files: [
                    {
                        expand: true,
                        src: nodeDir + '/bootstrap-icons/LICENSE.md',
                        dest: assetsDir + '/bootstrap-icons',
                        flatten: true
                    },
                    {
                        expand: true,
                        cwd: nodeDir + '/bootstrap-icons/font',
                        src: '**/*',
                        dest: assetsDir + '/bootstrap-icons',
                        flatten: false
                    }]
            },
            'tinymce': {
                files: [
                    {
                        expand: true,
                        cwd: nodeDir + '/tinymce',
                        src: ['license.txt', 'tinymce.min.js'],
                        dest: assetsDir + '/tinymce',
                        flatten: false
                    },
                    {
                        expand: true,
                        cwd: nodeDir + '/tinymce',
                        src: ['icons/**/**/*.min.*', 'skins/ui/oxide/**/*.min.*', 'skins/content/default/**/*.min.*', 'themes/silver/**/**/*.min.*'],
                        dest: assetsDir + '/tinymce',
                        flatten: false
                    },
                    {
                        expand: true,
                        cwd: nodeDir + '/tinymce/models/dom',
                        src: ['**/*.min.js'],
                        dest: assetsDir + '/tinymce/models/dom',
                        flatten: false
                    },
                    {
                        expand: true,
                        cwd: nodeDir + '/tinymce/skins',
                        src: ['content/default/content.min.css', 'ui/oxide/skin.min.css'],
                        dest: assetsDir + '/tinymce/skins',
                        flatten: false
                    },
                    {
                        expand: true,
                        cwd: nodeDir + '/tinymce/plugins',
                        src: ['advlist/*.min.js', 'anchor/*.min.js', 'autolink/*.min.js', 'charmap/*.min.js', 'code/*.min.js', 'fullscreen/*.min.js',
                            'image/*.min.js', 'imagetools/*.min.js', 'insertdatetime/*.min.js', 'link/*.min.js', 'lists/*.min.js', 'media/*.min.js', 'paste/*.min.js', 'preview/*.min.js',
                            'searchreplace/*.min.js', 'table/*.min.js', 'visualblocks/*.min.js'
                        ],
                        dest: assetsDir + '/tinymce/plugins',
                    }
                ]

            },
            'opensans-regular-font': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/npm-font-open-sans/fonts/Regular/OpenSans-Regular.ttf',
                        nodeDir + '/npm-font-open-sans/LICENSE',
                    ],
                    dest: assetsDir + '/fonts/open-sans',
                    flatten: true
                }]
            },
            'bootstrap': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/bootstrap/dist/css/bootstrap.min.*',
                        nodeDir + '/bootstrap/dist/js/bootstrap.min.*',
                        nodeDir + '/bootstrap/LICENSE'
                    ],
                    dest: assetsDir + '/bootstrap',
                    flatten: true
                }]
            },
            'osclass-legacy': {
                files: [{
                    expand: true,
                    cwd: nodeDir + '/osclass-legacy-assets/src',
                    src: '**/*',
                    dest: assetsDir + '/osclass-legacy/',
                    flatten: false
                }]
            },
            'bootstrap-scss': {
                files: [{
                    expand: true,
                    cwd: nodeDir + '/bootstrap/scss',
                    src: '**/*',
                    dest: 'oc-admin/themes/modern/scss/bootstrap',
                    flatten: false
                }
                ]
            },
            'chart-js': {
                files: [{
                    expand: true,
                    src: [
                        nodeDir + '/chart.js/dist/chart.min.js',
                        nodeDir + '/chart.js/LICENSE.md',
                    ],
                    dest: assetsDir + '/chart-js',
                    flatten: true
                }]
            },
            'sortablejs': {
                files:[{
                    expand: true,
                    src: [
                        nodeDir + '/sortablejs/Sortable.min.js',
                        nodeDir + '/sortablejs/LICENSE',
                    ],
                    dest: assetsDir + '/sortablejs',
                    flatten: true
                }]
            }
        },
        sass: {
            options: {
                style: 'nested'
            },
            dist: {
                files: {
                    'oc-admin/themes/modern/css/main.css':
                        'oc-admin/themes/modern/scss/main.scss'
                }
            }
        },
        uglify: {
            dist: {
                files: {
                    'oc-admin/themes/modern/js/location.min.js': ['oc-admin/themes/modern/js/location.js']
                },
                options: {
                    sourceMap: true
                }
            }
        }
    });

    // Custom task to create assets directory if not exists
    grunt.registerTask('createAssetsDir', 'Creates the necessary static assets directory', function () {
        if (!grunt.file.isDir(assetsDir)) {
            grunt.file.mkdir(assetsDir);
            grunt.log.ok('Directory "' + assetsDir + '" was created successfully.');
        }
    });

    // Define tasks
    grunt.registerTask('default', ['clean', 'createAssetsDir', 'copy', 'sass', 'uglify']);
    grunt.registerTask('compile', ['sass']);
};
