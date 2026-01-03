var IE = document.all?true:false;
if (IE) {
	document.attachEvent('onmousemove',getMouseXY);
} else {
	document.addEventListener('mousemove',getMouseXY,true);
}
//document.onmousemove = getMouseXY;

var tempX = 0;
var tempY = 0;
var wX = 0;
var wY = 0;

function getMouseXY(e) {
	if (IE) { // grab the x-y pos.s if browser is IE
		tempX = event.clientX;
		//tempX = event.clientX + document.body.scrollLeft;
		tempY = event.clientY + document.body.scrollTop - 20;
	} else {  // grab the x-y pos.s if browser is NS
		tempX = e.clientX;
		//tempX = e.pageX;
		tempY = e.pageY - 20;
	}
	wX = window.innerWidth;
	wY = window.innerHeight;
	return true;
}

function getXMLHttpObject() {
	if (window.XMLHttpRequest) {
		return new XMLHttpRequest();
	}
	if (window.ActiveXObject) {
		return new ActiveXObject("Microsoft.XMLHTTP");
	}
	return null;
}
function openInfo(url,uni,id) {
	var el = document.getElementById('overview');
	getOverviewInfo(url,uni,id);
	el.style.zIndex = 500;
	el.style.visibility = "visible";
}
function closeInfo() {
	var el = document.getElementById('overview');
	el.innerHTML = " ";
	el.style.zIndex = 0;
	el.style.visibility = "hidden";
	el.style.right = '0px';
	el.style.left = '0px';
}
function loadDetail(url,uni,id) {
	var el = document.getElementById('details');
	getDetailedInfo(url,uni,id);
	if (el.getAttribute('name') == "game") { 
		el.style.zIndex = 20;
	}
	el.style.visibility = "visible";
	//document.getElementById('close_detail').style.top = document.getElementById('header_side').clientHeight + 'px';
	document.getElementById('close_detail').style.visibility = "visible";
}
function closeDetail() {
	var el = document.getElementById('d_con');
	el.innerHTML = " ";
	el = document.getElementById('details');
	el.style.visibility = "hidden";
	if (el.getAttribute('name') == "game") { 
		//document.getElementById('overview').style.top = '0px';
		el.style.zIndex = 0;
		el.style.position = "fixed";
	}
	document.getElementById('close_detail').style.visibility = "hidden";
}

function getOverviewInfo(uri,uni,id) {
	var url = uri + "/info/overview_info.php";
	var params = "uni=" + uni + "&id=" + id;
	overviewhttp.open("POST",url,true);
	overviewhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	//overviewhttp.setRequestHeader("Content-length", params.length);
	//overviewhttp.setRequestHeader("Connection" , "close");
				
	overviewhttp.onreadystatechange = function () {
		if (overviewhttp.readyState == 4) {
			var el = document.getElementById('overview');
			el.innerHTML = overviewhttp.responseText;
			if (el.getAttribute('name') == "gem") {
				el.style.right = document.getElementById('gem_merchant').clientWidth + 20 + 'px';
			} else {
				var o_width = document.getElementById('overviewTable').clientWidth;
				//if (tempX >= 750) { el.style.right = '400px'; }
				//else { el.style.right = '0px'; }
				if (tempX + o_width >= wX) {
					el.style.right = wX - tempX - 10 + 'px';
					el.style.removeProperty("left");
				} else {
					el.style.left = tempX + 10 + 'px';
					el.style.removeProperty("right");
					if (el.style.left < 10) { el.style.left = '10px'; }
				}
			}
		}
	}
	overviewhttp.send(params);
}
function getDetailedInfo(uri,uni,id) {
	var url = uri + "/info/detailed_info.php";
	var params = "uni=" + uni + "&id=" + id;
	detailhttp.open("POST",url,true);
	detailhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	//detailhttp.setRequestHeader("Content-length", params.length);
	//detailhttp.setRequestHeader("Connection" , "close");
				
	detailhttp.onreadystatechange = function () {
		if (detailhttp.readyState == 4) {
			var el = document.getElementById('d_con');
			el.innerHTML = detailhttp.responseText;
			el = document.getElementById('details');
			if (el.getAttribute('name') == "game") {
				var s_width = document.getElementById('sectorTableMap').clientWidth;
				var d_width = document.getElementById('d_con').clientWidth;
				if (s_width + 100 + d_width <= wX) { el.style.left = s_width + 100 + 'px'; }
				else { el.style.right = '0px'; }
				//if (tempX >= 750) { el.style.right = '400px'; }
				//else { el.style.right = '0px'; }
				//document.getElementById('overview').style.top = el.clientHeight + 'px';
				if (el.clientHeight > wY) {
					el.style.position = 'absolute';
				} else { 
					el.style.position = 'fixed'; 
				}
			} else if (el.getAttribute('name') == "resources") {
				el.style.removeProperty("left"); // Clear any previous left positioning
				el.style.right = "150px";        // Align it to the right side of the viewport
			}
		}
	}
	detailhttp.send(params);
}


