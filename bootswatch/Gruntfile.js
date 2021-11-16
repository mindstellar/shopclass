module.exports = function (grunt) {
    grunt.loadNpmTasks('grunt-autoprefixer');
    grunt.loadNpmTasks('grunt-contrib-less');
    grunt.loadNpmTasks('grunt-contrib-sass');
    grunt.loadNpmTasks('grunt-contrib-concat');
    grunt.loadNpmTasks('grunt-contrib-connect');
    grunt.loadNpmTasks('grunt-contrib-clean');
    grunt.loadNpmTasks('grunt-contrib-watch');
    grunt.loadNpmTasks('grunt-contrib-copy');

    var configBridge = grunt.file.readJSON('./bower_components/bootstrap/grunt/configBridge.json', {encoding: 'utf8'});

    // Project configuration.
    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),
        builddir: '.',
        buildtheme: '',
        banner: '/*!\n' +
            ' * <%= pkg.name %> v<%= pkg.version %>\n' +
            ' * Homepage: <%= pkg.homepage %>\n' +
            ' * Copyright 2012-<%= grunt.template.today("yyyy") %> <%= pkg.author %>\n' +
            ' * Licensed under <%= pkg.license %>\n' +
            ' * Based on Bootstrap\n' +
            '*/\n',
        swatch: {

            bluemone: {},
            greenworld: {},
            seatail: {},
            readyred: {},
            orangelove: {},
            newgray: {},
            grayblue: {},
            nicepink: {}
        },
        clean: {
            build: {
                src: ['*/build.less', '*/build.scss', '!global/build.less', '!global/build.scss']
            }
        },
        concat: {
            options: {
                banner: '<%= banner %>',
                stripBanners: false
            },
            dist: {
                src: [],
                dest: ''
            }
        },
        less: {
            dist: {
                options: {
                    compress: false,
                    strictMath: true,
                    optimization: 2
                },
                files: {}
            }
        },
        autoprefixer: {
            options: {
                browsers: configBridge.config.autoprefixerBrowsers
            },
            dist: {
                src: '*/bootstrap.css'
            }
        },
        copy: {
            'fontawesome': {
                files: [
                    {
                        expand: true,
                        cwd: './bower_components/font-awesome/css',
                        src: '**/*',
                        dest: '../assets/css',
                        flatten: false
                    },
                    {
                        expand: true,
                        cwd: './bower_components/font-awesome/fonts',
                        src: '**/*',
                        dest: '../assets/fonts',
                        flatten: false
                    }
                ]
            }
        }
    });

    grunt.registerTask('none', function () {
    });

    grunt.registerTask('build', 'build a regular theme', function (theme, compress) {
        var theme = theme == undefined ? grunt.config('buildtheme') : theme;
        var compress = compress == undefined ? true : compress;

        var isValidTheme = grunt.file.exists('allthemes/' + theme, 'variables.less') && grunt.file.exists('allthemes/' + theme, 'bootswatch.less');

        // cancel the build (without failing) if this directory is not a valid theme
        if (!isValidTheme) {
            return;
        }

        var concatSrc;
        var concatDest;
        var lessDest;
        var lessSrc;
        var files = {};
        var dist = {};
        concatSrc = 'global/build.less';
        concatDest = 'allthemes/' + theme + '/build.less';
        //lessDest = '<%=builddir%>/allthemes/' + theme + '.css';
        lessDest = '../assets/css/theme/' + theme + '.css';
        lessSrc = ['allthemes/' + theme + '/' + 'build.less'];

        dist = {src: concatSrc, dest: concatDest};
        grunt.config('concat.dist', dist);
        files = {};
        files[lessDest] = lessSrc;
        grunt.config('less.dist.files', files);
        grunt.config('less.dist.options.compress', false);
        grunt.task.run(['concat', 'less:dist', 'prefix:' + lessDest, 'clean:build',
            compress ? 'compress:' + lessDest + ':' + '../assets/css/theme/' + theme + '.min.css' : 'none']);
    });

    grunt.registerTask('prefix', 'autoprefix a generic css', function (fileSrc) {
        grunt.config('autoprefixer.dist.src', fileSrc);
        grunt.task.run('autoprefixer');
    });

    grunt.registerTask('compress', 'compress a generic css', function (fileSrc, fileDst) {
        var files = {};
        files[fileDst] = fileSrc;
        grunt.log.writeln('compressing file ' + fileSrc);

        grunt.config('less.dist.files', files);
        grunt.config('less.dist.options.compress', true);
        grunt.task.run(['less:dist']);
    });
    grunt.registerMultiTask('swatch', 'build a theme', function () {
        var t = this.target;
        grunt.task.run('build:' + t);
    });

    /**
     * Regex borrowed form
     * https://gist.github.com/rosskevin/ddfe895091de2ca5f931
     * */
    grunt.registerTask('convert_less', 'Convert less to scss using regular expression', function () {
        var convertBaseDir = '';
        grunt.file.expand(convertBaseDir + '*/*.less').forEach(function (lessFile) {
            if (lessFile !== "global/build.less") {
                var srcContents = grunt.file.read(lessFile);
                var out = srcContents
                    // 1.  replace @ with $
                    .replace(/@(?!import|media|keyframes|-)/g, '$')
                    // 2.  replace mixins
                    .replace(/[\.#](?![0-9])([\w\-]*)\s*\((.*)\)\s*\{/g, '@mixin $1($2){')
                    // 3.  In LESS, bootstrap namespaces mixins, in SASS they are just prefixed e.g #gradient > .vertical-three-colors becomes @include gradient-vertical-three-colors
                    .replace(/[\.#](?![0-9])([\w\-]*)\s>\s\.(.*;)/g, '@include $1-$2')
                    // 4.  replace includes
                    .replace(/[\.#](?![0-9])([\w\-].*\(.*;)/g, '@include $1')
                    // 5.  replace no param mixin includes with empty parens
                    .replace(/@include\s([\w\-]*\s*);/g, '@include $1();')
                    // 6.  replace extends .class; @extend .class;
                    .replace(/(\.(?![0-9])([\w\-]+);)/g, '@extend $1')
                    // 7.  replace string literals
                    .replace(/~"(.*)"/g, '#{"$1"}')
                    // 8.  replace interpolation  ${var} > #{$var}
                    .replace(/\$\{(.*)\}/g, '#{$$$1}')
                    // 9.  replace spin to adjust-hue (function name diff)
                    .replace(/spin\(/g, 'adjust-hue(')
                    // 10. replace bower and imports in build.scss
                    .replace(/bootstrap\/less\//g, 'bootstrap-sass-official/assets/stylesheets/')
                    .replace(/\.less/g, '')
                    // 11. replace icon-font-path value with conditional for asset helpers
                    .replace(/(\$icon-font-path:).*;/g, '$1 if($bootstrap-sass-asset-helper, "bootstrap/", "../fonts/bootstrap/");')
                    // 12. set bootswatch's web-font-path value as !default
                    .replace(/(\$web-font-path:.*);/g, '$1 !default;')
                    // 13. Remove the web-font mixin which breaks in libsass
                    .replace(/[\s\S][\s\S]@mixin web-font.*([\s\S]*?).*;([\s\S]*?)}([\s\S]*?)/, "")
                    // 14. Replace usage of the web-font mixin with variable interpolation
                    .replace(/@include web-font/, '@import url');

                if (/\/variables.less$/.test(lessFile)) {
                    // 15. set default value of $bootstrap-sass-asset-helper to false
                    out = "$bootstrap-sass-asset-helper: false;\n" + out;
                    // 16. only assign variables if they haven't been previously set e.g. $var: #f00; > $var: #f00 !default;
                    out = out.replace(/^(\$.*);/gm, '$1 !default;');
                }

                var baseDirRegex = new RegExp("^" + convertBaseDir, "g");
                var sassFile = lessFile.replace(baseDirRegex, '').replace(/\.less$/, '.scss').replace(/(bootswatch|variables)/, '_$1');
                grunt.file.write(sassFile, out);
                grunt.log.writeln('Converted less file:  ', lessFile, Array(27 - lessFile.length).join(' '), '> ', sassFile);
            }
        });
    });

    grunt.registerTask('default', ['swatch','copy']);
};
