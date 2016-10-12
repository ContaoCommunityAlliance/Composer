$(window).addEvent('domready', function () {
    var output = $('output');
    var refreshIndicator = $('refresh-indicator');
    var uptime = $('uptime');
    var install = $('install');
    var errors = $('cloud_errors');
    var jobContainer = $('cloud_update_job_container');
    var jobId = $('cloud_update_job_id');

    var request = new Request.JSON({
        url: 'contao/main.php?do=composer',
        method: 'get',
        onSuccess: function (responseJSON) {
            output.innerHTML = responseJSON.output;
            uptime.innerHTML = responseJSON.uptime;
            errors.innerHTML = responseJSON.errors;

            if (responseJSON.jobId) {
                jobContainer.setStyle('display', 'block');
                jobId.innerHTML = responseJSON.jobId;
            }

            errors.toggleClass('invisible', !responseJSON.errors);

            if (responseJSON.isRunning) {
                setTimeout(function () {
                    run();
                }, 10);
            } else {
                install.setProperty('disabled', 'finished' !== responseJSON.jobStatus);
            }
        }
    });

    var timer = 0;

    function run() {
        timer++;

        refreshIndicator.setStyle('width', timer + '%');

        if (timer >= 100) {
            timer = 0;
            request.send();
        } else {
            setTimeout(function () {
                run();
            }, 30);
        }
    }

    run();
});
