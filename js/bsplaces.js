
function addGpx(map, gpxFiles, allPoints)
{
	for (var i = 0; i < gpxFiles.length; i++)
	{
		jQuery.ajax({
			type: 'GET',
			url: gpxFiles[i],
			dataType: 'xml',
			success: function(xmlDoc) {
				jQuery(xmlDoc).find('trkpt').each(function()
      			{
					var pt = SMap.Coords.fromWGS84(jQuery(this).attr('lon'), jQuery(this).attr('lat'));
					allPoints.push(pt);
				});
				var gpx = new SMap.Layer.GPX(xmlDoc, null, {maxPoints:600});
				map.addLayer(gpx);
				gpx.enable();
				if (i == gpxFiles.length) {
					var mapCenterZoom = map.computeCenterZoom(allPoints, true);
                    if (mapCenterZoom[1] > 13) mapCenterZoom[1] = 13;
					map.setCenterZoom(mapCenterZoom[0], mapCenterZoom[1]);
				};
    		},
  		});
	}
}

function addMarkers(map, markers, allPoints, useClusterer)
{
	var layer = new SMap.Layer.Marker();
	if (useClusterer)
	{
		var clusterer = new SMap.Marker.Clusterer(map);
		layer.setClusterer(clusterer);
	}
	map.addLayer(layer);
	layer.enable();

	for (var i = 0; i < markers.length; i++)
	{
		var pt = SMap.Coords.fromWGS84(markers[i][0], markers[i][1]);
		allPoints.push(pt);

		var c = new SMap.Card();
		//c.setSize(200, 100);
		c.getBody().style.margin = "5px 0px";
		//c.getHeader().innerHTML = "header";
		//c.getFooter().innerHTML = "footer";
	   	c.getBody().innerHTML = markers[i][2];
				
		//var options = { title: markers[i][2] };
		var options = { };
		var marker = new SMap.Marker(pt, "bsplaces_marker_" + i, options);
		marker.decorate(SMap.Marker.Feature.Card, c);
		layer.addMarker(marker);
	}
}
