import 'dart:io';
import 'dart:math' as math;

import 'package:image/image.dart' as image;

const _size = 1024;
final _night = image.ColorRgba8(10, 15, 28, 255);
final _teal = image.ColorRgba8(20, 184, 166, 255);
final _green = image.ColorRgba8(34, 197, 94, 255);

void main() {
  final foreground = image.Image(width: _size, height: _size, numChannels: 4);
  image.fill(foreground, color: image.ColorRgba8(0, 0, 0, 0));

  // Muted candles give the upward signal a recognizable trading context.
  for (final (x, top, bottom, bodyTop, bodyBottom, color) in [
    (322, 452, 706, 548, 647, image.ColorRgba8(42, 101, 106, 255)),
    (474, 368, 635, 430, 544, image.ColorRgba8(34, 123, 119, 255)),
    (629, 298, 515, 354, 456, image.ColorRgba8(28, 147, 121, 255)),
  ]) {
    image.drawLine(foreground,
        x1: x, y1: top, x2: x, y2: bottom, color: color, thickness: 12);
    image.fillRect(foreground,
        x1: x - 33, y1: bodyTop, x2: x + 33, y2: bodyBottom,
        radius: 15, color: color);
  }

  final path = [(230, 701), (394, 538), (516, 598), (750, 343)];
  for (var i = 0; i < path.length - 1; i++) {
    final (x1, y1) = path[i];
    final (x2, y2) = path[i + 1];
    final length = math.sqrt(math.pow(x2 - x1, 2) + math.pow(y2 - y1, 2));
    final dx = ((y2 - y1) * 23 / length).round();
    final dy = ((x1 - x2) * 23 / length).round();
    final color = i == path.length - 2 ? _green : _teal;
    image.fillPolygon(foreground, vertices: [
      image.Point(x1 + dx, y1 + dy), image.Point(x2 + dx, y2 + dy),
      image.Point(x2 - dx, y2 - dy), image.Point(x1 - dx, y1 - dy),
    ], color: color);
    image.fillCircle(foreground,
        x: x1, y: y1, radius: 23, color: color, antialias: true);
  }

  image.fillCircle(foreground,
      x: 750, y: 343, radius: 59,
      color: image.ColorRgba8(34, 197, 94, 65), antialias: true);
  image.fillCircle(foreground,
      x: 750, y: 343, radius: 39, color: _green, antialias: true);
  image.fillCircle(foreground,
      x: 750, y: 343, radius: 12, color: _night, antialias: true);

  final icon = image.Image(width: _size, height: _size, numChannels: 4);
  image.fill(icon, color: _night);
  image.compositeImage(icon, foreground);

  File('assets/icon/icon.png').writeAsBytesSync(image.encodePng(icon));
  File('assets/icon/icon_foreground.png')
      .writeAsBytesSync(image.encodePng(foreground));
}
