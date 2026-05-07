<div class="card-header">
    <button id="prnt" calss="btn btn-lg bg-slate-100" title="2"><span class="fa fa-print" ></span></button>
    <button id="copybtn" calss="btn btn-lg bg-slate-100" title="2"><span class="fa fa-copy" ></span></button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('prnt').addEventListener('click', function() {
        var horsReport = document.getElementById('horsReport');
        if (horsReport) {
            var printContents = horsReport.outerHTML;
            var originalContents = document.body.innerHTML;

            document.body.innerHTML = printContents;

            window.print();

            document.body.innerHTML = originalContents;
        } else {
            console.error('Element with id "horsReport" not found');
        }
    });

    document.getElementById('copybtn').addEventListener('click', function() {
        var report = document.getElementById('horsReport');
        if (report) {
            var range = document.createRange();
            range.selectNode(report);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            
            try {
            var successful = document.execCommand('copy');
            var msg = successful ? 'successful' : 'unsuccessful';
            console.log('Copying text command was ' + msg);
            if (successful) {
                alert('Content copied successfully!');
            } else {
                alert('Copy operation was unsuccessful. Please try again.');
            }
        } catch (err) {
            console.log('Oops, unable to copy');
            alert('An error occurred while trying to copy. Please try again.');
        }
    }
    });
});


</script>