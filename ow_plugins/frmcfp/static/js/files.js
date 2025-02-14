var uploadFileIntoCFPFormComponent;

function showUploadFileIntoCFPForm($eventId){
    uploadFileIntoCFPFormComponent = OW.ajaxFloatBox('FRMCFP_CMP_FileUploadFloatBox', {iconClass: 'ow_ic_add',eventId: $eventId})
}

function closeUploadFileIntoCFPForm(){
    if(uploadFileIntoCFPFormComponent){
        uploadFileIntoCFPFormComponent.close();
    }
}

function searchCFP(url) {
    var searchT = $('#searchTitle')[0].value;
    var categoryS = $('#categoryStatus')[0].value;
    var filter = "?searchTitle="+searchT+"&categoryStatus="+categoryS;
    if($('#dateStatus').length >0) {
        var dateS = $('#dateStatus')[0].value;
        filter= filter + "&dateStatus=" + dateS;
    }
    if($('#participationStatus').length >0) {
        var participationS = $('#participationStatus')[0].value;
        filter= filter + "&participationStatus=" + participationS;
    }

    url = url + filter;
    window.location = url;
}