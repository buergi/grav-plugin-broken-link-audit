((function($) {
    $(document).ready(function() {
        var Request, Toastr = null;
        if (typeof Grav !== 'undefined' && Grav && Grav.default && Grav.default.Utils) {
            Request = Grav.default.Utils.request;
            Toastr = Grav.default.Utils.toastr;
        }
        var indexer = $('#brokenLinkAudit-rescan, #admin-nav-quick-tray .brokenLinkAudit-rescan'),
            current = null, currentTray = null;
        if (!indexer.length) { return; }

        indexer.on('click', function(e) {
            e.preventDefault();
            $.ajax({
                type: 'POST',
                url: GravAdmin.config.base_url_relative + '.json/task' + GravAdmin.config.param_sep + 'rescanBrokenLinkAudit',
                data: { 'admin-nonce': GravAdmin.config.admin_nonce }
            }).done(function(done) {
                console.log("bla done",done)
                if (done.status === 'success') {
                    Toastr.success(done.message);
                }
                else {
                    var error = done.message;
                    Toastr.error(error);
                }
            }).fail(function(error) {
                console.log("bla error",error)
            });
        });
    });
})(jQuery));
